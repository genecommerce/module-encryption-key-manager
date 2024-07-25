<?php

declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Service;

use Magento\Deploy\Model\DeploymentConfig\Hash;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\Config\Data\ConfigData;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Encryption\EncryptorInterfaceFactory;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;

class ReencryptEnvSystemConfigurationValues
{
    /** @var EncryptorInterface|null  */
    private $encryptor = null;

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param Writer $writer
     * @param EncryptorInterfaceFactory $encryptorFactory
     * @param Hash $hash
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly Writer $writer,
        private readonly EncryptorInterfaceFactory $encryptorFactory,
        private readonly Hash $hash
    ) {
    }

    /**
     * Gather all encrypted system config values from env.php and re-encrypt them
     *
     * @return void
     * @throws FileSystemException
     * @throws RuntimeException
     * @throws \Exception
     */
    public function execute(): void
    {
        $this->deploymentConfig->resetData();
        $this->encryptor = $this->encryptorFactory->create();
        $systemConfig = $this->deploymentConfig->get('system');
        $systemConfig = $this->iterateSystemConfig($systemConfig);

        $encryptSegment = new ConfigData(ConfigFilePool::APP_ENV);
        $encryptSegment->set('system', $systemConfig);
        $this->writer->saveConfig([$encryptSegment->getFileKey() => $encryptSegment->getData()]);

        /**
         * @see \Magento\Deploy\Console\Command\App\ConfigImport\Processor::execute()
         */
        $this->hash->regenerate('system');
    }

    /**
     * Recursively iterate through the system configuration and re-encrypt any encrypted values
     *
     * @param array $systemConfig
     * @return array
     * @throws \Exception
     */
    private function iterateSystemConfig(array $systemConfig): array
    {
        foreach ($systemConfig as $key => &$value) {
            if (is_array($value)) {
                $value = $this->iterateSystemConfig($value);
            } elseif (is_string($value) && preg_match('/^\d+:\d+:.*$/', $value)) {
                $decryptedValue = $this->encryptor->decrypt($value);
                if ($decryptedValue) {
                    $value = $this->encryptor->encrypt($decryptedValue);
                }
            }
        }

        return $systemConfig;
    }
}
