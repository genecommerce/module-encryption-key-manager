<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Console;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ReencryptUnhandledCoreConfigData extends Command
{
    public const INPUT_KEY_FORCE = 'force';

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
            )
        ];

        $this->setName('gene:encryption-key-manager:reencrypt-unhandled-core-config-data');
        $this->setDescription('Re-encrypt unhandled core config data with the latest key');
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

            /*
             * Get all values which look like an encrypted value, that are not for the latest key
             */
            $ccdTable = $this->resourceConnection->getTableName('core_config_data');
            $connection = $this->resourceConnection->getConnection();

            if (!$connection->isTableExists($ccdTable)) {
                $output->writeln("<info>The table {$ccdTable} doesn't exist</info>");
                return Cli::RETURN_SUCCESS;
            }

            $select = $connection->select()
                ->from($ccdTable, ['*'])
                ->where('(value LIKE "_:_:____%" OR value LIKE "__:_:____%")')
                ->where('value NOT LIKE ?', "$latestKeyNumber:_:__%")
                ->where('value NOT LIKE ?', "a:%")
                ->where('value NOT LIKE ?', "s:%");

            $result = $connection->fetchAll($select);
            if (empty($result)) {
                $output->writeln('No old entries found');
                return Cli::RETURN_SUCCESS;
            }
            foreach ($result as $row) {
                $output->writeln(str_pad('', 120, '#'));
                $output->writeln("config_id: {$row['config_id']}");
                foreach ($row as $field => $value) {
                    if (in_array($field, ['value', 'config_id'])) {
                        continue;
                    }
                    $output->writeln("$field: $value");
                }
                $value = $row['value'];
                $output->writeln("ciphertext_old: " . $value);
                $valueDecrypted = $this->encryptor->decrypt($value);
                $output->writeln("plaintext: " . $valueDecrypted);
                $valueEncrypted = $this->encryptor->encrypt($valueDecrypted);
                $output->writeln("ciphertext_new: " . $valueEncrypted);

                if ($input->getOption(self::INPUT_KEY_FORCE)) {
                    $connection->update(
                        $ccdTable,
                        ['value' => $valueEncrypted],
                        ['config_id = ?' => (int)$row['config_id']]
                    );
                } else {
                    $output->writeln('Dry run mode, no changes have been made');
                }
                $output->writeln(str_pad('', 120, '#'));
            }

            $this->cache->clean();
            $output->writeln('Done');
        } catch (\Throwable $throwable) {
            $output->writeln("<error>" . $throwable->getMessage() . "</error>");
            $output->writeln($throwable->getTraceAsString(), OutputInterface::VERBOSITY_VERBOSE);
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }
}
