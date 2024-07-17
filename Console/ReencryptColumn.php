<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Console;

use Composer\Console\Input\InputArgument;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Console\Cli;
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
     * @param DeploymentConfig $deploymentConfig
     * @param ResourceConnection $resourceConnection
     * @param EncryptorInterface $encryptor
     * @param CacheInterface $cache
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ResourceConnection $resourceConnection,
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
            $output->writeln("Looking for '$column' in '$table', identified by '$identifier'");

            /**
             * @see \Magento\Framework\Model\ResourceModel\Db\AbstractDb::_getLoadSelect()
             */
            $tableName = $this->resourceConnection->getTableName($table);
            $connection = $this->resourceConnection->getConnection();
            $field = $connection->quoteIdentifier(sprintf('%s.%s', $tableName, $column));

            $select = $connection->select()
                ->from($tableName, [$identifier, "$column"])
                ->where("($field LIKE '_:_:____%' OR $field LIKE '__:_:____%')")
                ->where("$field NOT LIKE ?", "$latestKeyNumber:_:__%");

            $result = $connection->fetchAll($select);
            if (empty($result)) {
                $output->writeln('No old entries found');
                return Cli::RETURN_SUCCESS;
            }
            $connection->beginTransaction();
            foreach ($result as $row) {
                $output->writeln(str_pad('', 120, '#'));
                $output->writeln("$identifier: {$row[$identifier]}");
                $value = $row[$column];
                $output->writeln("ciphertext_old: " . $value);
                $valueDecrypted = $this->encryptor->decrypt($value);
                $output->writeln("plaintext: " . $valueDecrypted);
                $valueEncrypted = $this->encryptor->encrypt($valueDecrypted);
                $output->writeln("ciphertext_new: " . $valueEncrypted);

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
