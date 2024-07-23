<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Service;

use Magento\Config\Model\Config\Structure;
use Magento\EncryptionKey\Model\ResourceModel\Key\Change as MageChanger;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\Config\Data\ConfigData;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\Filesystem;
use Magento\Framework\Math\Random;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeEncryptionKey extends MageChanger
{
    /** @var OutputInterface|null */
    private $output;

    /** @var bool  */
    private $skipSavedCreditCards = false;

    /**
     * @param Context $context
     * @param Filesystem $filesystem
     * @param Structure $structure
     * @param EncryptorInterface $encryptor
     * @param Writer $writer
     * @param Random $random
     * @param DeploymentConfig $deploymentConfig
     * @param $connectionName
     */
    public function __construct(
        Context $context,
        Filesystem $filesystem,
        Structure $structure,
        EncryptorInterface $encryptor,
        Writer $writer,
        Random $random,
        private readonly DeploymentConfig $deploymentConfig,
        $connectionName = null,
    ) {
        parent::__construct($context, $filesystem, $structure, $encryptor, $writer, $random, $connectionName);
    }

    /**
     * @param OutputInterface $output
     * @return void
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * @param bool $skipSavedCreditCards
     * @return void
     */
    public function setSkipSavedCreditCards($skipSavedCreditCards)
    {
        $this->skipSavedCreditCards = (bool) $skipSavedCreditCards;
    }

    /**
     * @param string $text
     * @return void
     */
    private function writeOutput($text)
    {
        if ($this->output instanceof OutputInterface) {
            $this->output->writeln($text);
        }
    }

    /**
     * Gather all encrypted system config values and re-encrypt them
     *
     * @return void
     */
    protected function _reEncryptSystemConfigurationValues()
    {
        $this->writeOutput('_reEncryptSystemConfigurationValues - start');
        parent::_reEncryptSystemConfigurationValues();
        $this->writeOutput('_reEncryptSystemConfigurationValues - end');
    }

    /**
     * Gather saved credit card numbers from sales order payments and re-encrypt them
     *
     * The parent function does not handle null values, so this version filters them out as well as adding CLI output
     *
     * @return void
     */
    protected function _reEncryptCreditCardNumbers()
    {
        if ($this->skipSavedCreditCards) {
            $this->writeOutput('_reEncryptCreditCardNumbers - skipping');
            return;
        }
        $this->writeOutput('_reEncryptCreditCardNumbers - start');
        $table = $this->getTable('sales_order_payment');
        $select = $this->getConnection()->select()->from($table, ['entity_id', 'cc_number_enc']);

        $attributeValues = $this->getConnection()->fetchPairs($select);
        // save new values
        foreach ($attributeValues as $valueId => $value) {
            // GENE CHANGE START
            if (!$value) {
                continue;
            }
            // GENE CHANGE END
            $this->getConnection()->update(
                $table,
                ['cc_number_enc' => $this->encryptor->encrypt($this->encryptor->decrypt($value))],
                ['entity_id = ?' => (int)$valueId]
            );
        }
        $this->writeOutput('_reEncryptCreditCardNumbers - end');
    }

    /**
     * Gather all encrypted system config values from env.php and re-encrypt them
     *
     * @return void
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function reEncryptEnvConfigurationValues(): void
    {
        $this->writeOutput('_reEncryptEnvConfigurationValues - start');
        $systemConfig = $this->deploymentConfig->get('system');
        $systemConfig = $this->iterateSystemConfig($systemConfig);

        $encryptSegment = new ConfigData(ConfigFilePool::APP_ENV);
        $encryptSegment->set('system', $systemConfig);
        $this->writer->saveConfig([$encryptSegment->getFileKey() => $encryptSegment->getData()]);
        $this->writeOutput('_reEncryptEnvConfigurationValues - end');
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
                $value = $this->encryptor->encrypt($this->encryptor->decrypt($value));
            }
        }

        return $systemConfig;
    }
}
