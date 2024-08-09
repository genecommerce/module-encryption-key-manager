<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Service;

use Magento\EncryptionKey\Model\ResourceModel\Key\Change as MageChanger;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeEncryptionKey extends MageChanger
{
    /** @var OutputInterface|null */
    private $output = null;

    /** @var bool */
    private $skipSavedCreditCards = false;

    /**
     * Set the OutputInterface for logging output.
     *
     * @param OutputInterface $output
     * @return void
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;
    }

    /**
     * Set whether to skip re-encryption of saved credit cards.
     *
     * @param bool $skipSavedCreditCards
     * @return void
     */
    public function setSkipSavedCreditCards($skipSavedCreditCards)
    {
        $this->skipSavedCreditCards = (bool) $skipSavedCreditCards;
    }

    /**
     * Write a message to the output if it's set.
     *
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
     * Gather all encrypted system config values and re-encrypt them.
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
     * Gather saved credit card numbers from sales order payments and re-encrypt them.
     *
     * The parent function does not handle null values, so this version filters them out as well as adding CLI output.
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

        // Save new values
        foreach ($attributeValues as $valueId => $value) {
            if (!$value) {
                continue;
            }
            $this->getConnection()->update(
                $table,
                ['cc_number_enc' => $this->encryptor->encrypt($this->encryptor->decrypt($value))],
                ['entity_id = ?' => (int)$valueId]
            );
        }
        $this->writeOutput('_reEncryptCreditCardNumbers - end');
    }
}
