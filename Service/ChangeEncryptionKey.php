<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Service;

use Magento\EncryptionKey\Model\ResourceModel\Key\Change as MageChanger;
use Symfony\Component\Console\Output\OutputInterface;

class ChangeEncryptionKey extends MageChanger
{
    /** @var OutputInterface|null */
    private $output;

    /** @var bool  */
    private $skipSavedCreditCards = false;

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
     * @param $text
     * @param int $type
     * @return void
     */
    private function writeOutput($text, $type = OutputInterface::OUTPUT_NORMAL)
    {
        if ($this->output instanceof OutputInterface) {
            $this->output->writeln($text, $type);
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

        $batchSize = 10000; // TODO worth making configurable?

        $minId = (int) $this->getConnection()->fetchOne(
            $this->getConnection()->select()
                ->from($table, ['min(entity_id) AS min_id'])
        );
        $maxId = (int) $this->getConnection()->fetchOne(
            $this->getConnection()->select()
                ->from($table, ['max(entity_id) AS max_id'])
        );
        $totalCount = ($maxId - $minId) + 1; // the numbers are inclusive so add 1

        $numberOfBatches = ceil($totalCount / $batchSize);
        $this->writeOutput("_reEncryptCreditCardNumbers - total possible records: $totalCount");
        $this->writeOutput("_reEncryptCreditCardNumbers - batch size:             $batchSize");
        $this->writeOutput("_reEncryptCreditCardNumbers - batch count:            $numberOfBatches");

        $updatedCount = 0;
        $currentMin = $minId;
        for ($i = 0; $i < $numberOfBatches; $i++) {
            $currentMax = $currentMin + $batchSize;
            $select = $this->getConnection()->select()
                ->from($table, ['entity_id', 'cc_number_enc'])
                ->where("entity_id >= ?", $currentMin)
                ->where("entity_id < ?", $currentMax);

            $this->writeOutput((string)$select, OutputInterface::VERBOSITY_VERBOSE);

            $attributeValues = $this->getConnection()->fetchPairs($select);
            foreach ($attributeValues as $valueId => $value) {
                if (!$value) {
                    continue;
                }
                // TODO can we collect these, and do the updates in batches as the updates are the limiting factor
                // https://stackoverflow.com/a/3466
                $this->getConnection()->update(
                    $table,
                    ['cc_number_enc' => $this->encryptor->encrypt($this->encryptor->decrypt($value))],
                    ['entity_id = ?' => (int)$valueId]
                );
                $updatedCount++;
            }
            $currentMin = $currentMax;
            $this->writeOutput("running total records updated: $updatedCount", OutputInterface::VERBOSITY_VERBOSE);
        }

        $this->writeOutput("_reEncryptCreditCardNumbers - total records updated:   $updatedCount");
        $this->writeOutput('_reEncryptCreditCardNumbers - end');
    }
}
