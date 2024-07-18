<?php declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Console;

use Gene\EncryptionKeyManager\Model\RecursiveDataProcessor;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReencryptTfaData extends Command
{
    public const INPUT_KEY_FORCE = 'force';

    public const TFA_TABLE = 'tfa_user_config';

    /**
     * @param DeploymentConfig $deploymentConfig
     * @param ResourceConnection $resourceConnection
     * @param EncryptorInterface $encryptor
     * @param CacheInterface $cache
     * @param RecursiveDataProcessor $recursiveDataProcessor
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig,
        private readonly ResourceConnection $resourceConnection,
        private readonly EncryptorInterface $encryptor,
        private readonly CacheInterface $cache,
        private readonly RecursiveDataProcessor $recursiveDataProcessor,
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
        ];

        $this->setName('gene:encryption-key-manager:reencrypt-tfa-data');
        $this->setDescription('Re-encrypts tfa_user_config data with the latest key');
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
            $output->writeln('<error>This CLI has only been tested with Google Authenticator (TOTP) and U2F (Yubikey, etc). If you use Authy or DUO you *MUST* verify before use.</error>');
        }

        try {
            $keys = preg_split('/\s+/s', trim((string)$this->deploymentConfig->get('crypt/key')));
            $latestKeyNumber = count($keys) - 1;
            $output->writeln("The latest encryption key is number $latestKeyNumber, looking for old entries");


            $table = self::TFA_TABLE;
            $identifier = 'config_id';
            $column = 'encoded_config';
            $output->writeln("Looking for $column in $table, identified by '$identifier'");

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
                $valueDecrypted = json_decode($valueDecrypted);

                /**
                 * Google Authenticator 2FA provider uses a nested encrypted value. So that we can also handle other
                 * providers that may do the same, recursively process the originally decrypted value, re-encrypting
                 * its children.
                 */
                $valueDecrypted = $this->recursiveDataProcessor->down($valueDecrypted);
                // For test purposes
                if (isset($valueDecrypted->google->secret)) {
                    $nestedPlaintext = $this->encryptor->decrypt($valueDecrypted->google->secret);
                    $output->writeln("nested_plaintext: $nestedPlaintext");
                }
                $valueDecrypted = json_encode($valueDecrypted);
                $output->writeln("plaintext_new: " . $valueDecrypted);
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
