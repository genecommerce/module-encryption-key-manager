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

            /**
             * @see https://github.com/magento/inventory/blob/750be5b07053331bc0bf3cb0f4d19366a67694f4/Inventory/Model/ResourceModel/SourceItem/SaveMultiple.php#L165-L188
             */
            $pairsToUpdate = [];
            $attributeValues = $this->getConnection()->fetchPairs($select);
            foreach ($attributeValues as $valueId => $value) {
                if (!$value) {
                    continue;
                }
                // TODO i think the encryption is the limiting factor here
                // TODO i can insert 1 million rows as asdfasdfsdfasdf in ~8 seconds locally but ~2 mins for the full process
//                $pairsToUpdate[(int)$valueId] = 'asdfasdfsdfasdf';
                $pairsToUpdate[(int)$valueId] = $this->encryptor->encrypt($this->encryptor->decrypt($value));
                $updatedCount++;
            }

            $currentMin = $currentMax;
            if (empty($pairsToUpdate)) {
                continue;
            }

            $columnsSql = $this->buildColumnsSqlPart(['entity_id', 'cc_number_enc']);
            $valuesSql = $this->buildValuesSqlPart($pairsToUpdate);
            $onDuplicateSql = $this->buildOnDuplicateSqlPart(['cc_number_enc']);
            $bind = $this->getSqlBindData($pairsToUpdate);

            // todo worth checking this isnt artifically inflating the auto increment id
            $insertSql = sprintf(
                'INSERT INTO `%s` (%s) VALUES %s %s',
                $table,
                $columnsSql,
                $valuesSql,
                $onDuplicateSql
            );
            $this->getConnection()->query($insertSql, $bind);
            $this->writeOutput("running total records updated: $updatedCount", OutputInterface::VERBOSITY_VERBOSE);
        }

        $this->writeOutput("_reEncryptCreditCardNumbers - total records updated:   $updatedCount");
        $this->writeOutput('_reEncryptCreditCardNumbers - end');
    }

    /**
     * Build sql query for on duplicate event
     *
     * @param array $fields
     * @return string
     */
    private function buildOnDuplicateSqlPart(array $fields): string
    {
        $connection = $this->getConnection();
        $processedFields = [];
        foreach ($fields as $field) {
            $processedFields[] = sprintf('%1$s = VALUES(%1$s)', $connection->quoteIdentifier($field));
        }
        $sql = 'ON DUPLICATE KEY UPDATE ' . implode(', ', $processedFields);
        return $sql;
    }

    /**
     * Build column sql part
     *
     * @param array $columns
     * @return string
     */
    private function buildColumnsSqlPart(array $columns): string
    {
        $connection = $this->getConnection();
        $processedColumns = array_map([$connection, 'quoteIdentifier'], $columns);
        $sql = implode(', ', $processedColumns);
        return $sql;
    }

    /**
     * Build sql query for values
     *
     * @param array $rows
     * @return string
     */
    private function buildValuesSqlPart(array $rows): string
    {
        $sql = rtrim(str_repeat('(?, ?), ', count($rows)), ', ');
        return $sql;
    }

    /**
     * Get Sql bind data
     *
     * @param array $rows
     * @return array
     */
    private function getSqlBindData(array $rows): array
    {
        $bind = [];
        foreach ($rows as $id => $encryptedValue) {
            $bind[] = $id;
            $bind[] = $encryptedValue;
        }
        return $bind;
    }
}
