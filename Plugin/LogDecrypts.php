<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Plugin;

use Psr\Log\LoggerInterface;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\App\DeploymentConfig;
use Gene\EncryptionKeyManager\Model\EncodingHelper;

class LogDecrypts
{
    /** @var int  */
    private $keyCount = 0;

    /** @var bool  */
    private $enabled = false;

    /** @var bool  */
    private $onlyLogOldKeyDecryptions = false;

    /**
     * @param Encryptor $encryptor
     * @param DeploymentConfig $deploymentConfig
     * @param LoggerInterface $logger
     * @throws \Magento\Framework\Exception\FileSystemException
     * @throws \Magento\Framework\Exception\RuntimeException
     */
    public function __construct(
        Encryptor $encryptor,
        DeploymentConfig $deploymentConfig,
        private readonly LoggerInterface $logger,
        private readonly EncodingHelper $encodingHelper
    ) {
        $this->keyCount = count(explode(PHP_EOL, $encryptor->exportKeys())) - 1;

        // These need to come from deployment config because it is triggered so early in the request flow
        $this->enabled = (bool)$deploymentConfig->get(
            'system/default/dev/debug/gene_encryption_manager_enable_decrypt_logging',
            false
        );
        $this->onlyLogOldKeyDecryptions = (bool)$deploymentConfig->get(
            'system/default/dev/debug/gene_encryption_manager_only_log_old_decrypts',
            false
        );
    }

    /**
     * Log the source of a decryption, so that we can verify all keys are properly rotated
     *
     * @param Encryptor $subject
     * @param $result
     * @param $data
     * @return mixed
     */
    public function afterDecrypt(Encryptor $subject, $result, $data)
    {
        if (!$this->enabled) {
            return $result;
        }
        try {
            if (!(is_string($data) && strlen($data) > 5)) {
                // Not a string matching '0:0:X' or longer
                return $result;
            }
            if ($this->onlyLogOldKeyDecryptions && str_starts_with($data, $this->keyCount . ':')) {
                // We are decrypting a value identified by the current maximum key, no need to log
                return $result;
            }

            // don't logging values don't like as an encrypted value
            if (!$this->encodingHelper->isEncryptedValue($data)) {
                return $result;
            }

            $exception = new \Exception();

            /**
             * Generate a trace identifier based on the stack trace excluding any args
             */
            $traceIdentifier = [];
            foreach ($exception->getTrace() as $trace) {
                unset($trace['args']);
                $traceIdentifier[] = implode($trace);
            }
            $traceIdentifier = md5(implode($traceIdentifier));

            /**
             * This is a bit odd looking but it puts the entire trace in one line, many log management systems do not
             * like multi line logs and this can make it a bit easier to trace / filter in those systems
             *
             * Make the log entry single pipe separated line
             * Remove full path from trace for easier reading
             * BP defined at app/autoload.php
             */
            $traceString = str_replace(PHP_EOL, '|', $exception->getTraceAsString());
            $traceString = '|' . str_replace(BP . '/', '', $traceString);

            $this->logger->info(
                'gene encryption manager - legacy decryption',
                [
                    'trace_id' => $traceIdentifier,
                    'trace' => $traceString
                ]
            );
        } catch (\Throwable $throwable) {
            $this->logger->error(
                'gene encryption manager - error logging',
                [
                    'message' => $throwable->getMessage(),
                ]
            );
        }
        return $result;
    }
}
