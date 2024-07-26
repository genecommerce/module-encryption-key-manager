<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Console;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Serialize\Serializer\Json as JsonSerializer;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReencryptColumn extends Command
{
    public const INPUT_KEY_FORCE = 'force';
    public const INPUT_KEY_TABLE = 'table';
    public const INPUT_KEY_IDENTIFIER = 'identifier';
    public const INPUT_KEY_COLUMN = 'column';

    /**
     * @param Magento\Framework\App\DeploymentConfig $deploymentConfig
     * @param Magento\Framework\App\ResourceConnection $resourceConnection
     * @param Magento\Framework\Serialize\Serializer\Json $jsonSerializer
     * @param Magento\Framework\Encryption\EncryptorInterface $encryptor
     * @param Magento\Framework\App\CacheInterface $cache
     * @return void
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly JsonSerializer $jsonSerializer,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache
    ) {
        parent::__construct();
    }

    /**
     * @return void
     */
    protected function configure()
    {
        $options = [
            new InputOption(
                self::INPUT_KEY_FORCE,
                null,
                InputOption::VALUE_NONE,
                'Whether to force this action to take effect'
            ),
            new InputArgument(
                self::INPUT_KEY_TABLE,
                null,
                'The table containing the data to re-encrypt',
                ''
            ),
            new InputArgument(
                self::INPUT_KEY_IDENTIFIER,
                null,
                'The entity_id, row_id, or equivalent for the table',
                ''
            ),
            new InputArgument(
                self::INPUT_KEY_COLUMN,
                null,
                'The column that you want to re-encrypt',
                ''
            )
        ];

        $this->setName('gene:encryption-key-manager:reencrypt-column');
        $this->setDescription('Re-encrypt a columns data with the latest key');
        $this->setDefinition($options);

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption(self::INPUT_KEY_FORCE)) {
            $output->writeln('<info>Run with --force to make these changes, this will run in dry-run mode by default</info>');
        }

        try {
            $keys = preg_split('/\s+/s', trim((string)$this->deploymentConfig->get('crypt/key')));
            $latestKeyNumber = count($keys) - 1;
            $output->writeln("The latest encryption key is number $latestKeyNumber, looking for old entries");

            $table = $input->getArgument(self::INPUT_KEY_TABLE);
            if (!strlen($table)) {
                throw new \Exception('Provide a table name');
            }
            if (in_array($table, ['core_config_data', 'tfa_user_config'])) {
                throw new \Exception('You cannot use this command for this table');
            }
            $identifier = $input->getArgument(self::INPUT_KEY_IDENTIFIER);
            if (!strlen($identifier)) {
                throw new \Exception('Provide an identifier');
            }
            $column = $input->getArgument(self::INPUT_KEY_COLUMN);
            if (!strlen($column)) {
                throw new \Exception('Provide an column');
            }
            $jsonField = null;
            if (strpos($column, '.') !== false) {
                list($column, $jsonField) = explode('.', $column);
                $output->writeln(
                    "Looking for JSON field '$jsonField.$column' in '$table', identified by '$identifier'"
                );
            } else {
                $output->writeln("Looking for '$column' in '$table', identified by '$identifier'");
            }
            /**
             * @see \Magento\Framework\Model\ResourceModel\Db\AbstractDb::_getLoadSelect()
             */
            $tableName = $this->resourceConnection->getTableName($table);
            $connection = $this->resourceConnection->getConnection();
            $field = $connection->quoteIdentifier(sprintf('%s.%s', $tableName, $column));
            $select = $connection->select()
                    ->from($tableName, [$identifier, "$column"]);
            if ($jsonField === null) {
                $select = $select->where("($field LIKE '_:_:____%' OR $field LIKE '__:_:____%')")
                    ->where("$field NOT LIKE ?", "$latestKeyNumber:_:__%");
            } else {
                $select = $select->where("($field LIKE '{%_:_:____%}' OR $field LIKE '{%__:_:____%}')");
            }
            $result = $connection->fetchAll($select);
            if (empty($result)) {
                $output->writeln('No old entries found');
                return Cli::RETURN_SUCCESS;
            }
            $connection->beginTransaction();
            $noResults = true;
            foreach ($result as $row) {
                $output->writeln(str_pad('', 120, '#'));
                $value = $row[$column];
                $fieldData = [];
                if ($jsonField !== null) {
                    $fieldData = $this->jsonSerializer->unserialize($value);
                    $value = $fieldData[$jsonField] ?? '';
                    // Prevent re-processing fields & processing empty fields
                    if (strpos($value, "$latestKeyNumber:") === 0 || $value === '') {
                        continue;
                    }
                }
                $noResults = false;
                $output->writeln("$identifier: {$row[$identifier]}");
                $output->writeln("ciphertext_old: " . $value);
                $valueDecrypted = $this->encryptor->decrypt($value);
                $output->writeln("plaintext: " . $valueDecrypted);
                $valueEncrypted = $this->encryptor->encrypt($valueDecrypted);
                $output->writeln("ciphertext_new: " . $valueEncrypted);

                if ($jsonField !== null) {
                    $fieldData[$jsonField] = $valueEncrypted;
                    $valueEncrypted = $this->jsonSerializer->serialize($fieldData);
                }

                if ($input->getOption(self::INPUT_KEY_FORCE)) {
                    $connection->update(
                        $tableName,
                        [$column => $valueEncrypted],
                        ["$identifier = ?" => $row[$identifier]]
                    );
                } else {
                    $output->writeln('Dry run mode, no changes have been made');
                }
                $output->writeln(str_pad('', 120, '#'));
            }

            if ($noResults) {
                $output->writeln('No old entries found');
            }

            $connection->commit();
            $this->cache->clean();
            $output->writeln('Done');
        } catch (\Throwable $throwable) {
            if ($this->resourceConnection->getConnection()->getTransactionLevel() > 0) {
                $this->resourceConnection->getConnection()->rollBack();
            }
            $output->writeln("<error>" . $throwable->getMessage() . "</error>");
            $output->writeln($throwable->getTraceAsString(), OutputInterface::VERBOSITY_VERBOSE);
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }
}
