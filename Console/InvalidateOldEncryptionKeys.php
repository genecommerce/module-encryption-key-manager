<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Console;

use Magento\Framework\App\DeploymentConfig\Writer;
use Magento\Framework\Config\Data\ConfigData;
use Magento\Framework\Config\File\ConfigFilePool;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class InvalidateOldEncryptionKeys extends Command
{
    public const INPUT_KEY_FORCE = 'force';

    public function __construct(
        private readonly Writer $writer,
        private readonly CacheInterface $cache,
        private readonly DeploymentConfig $deploymentConfig
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

        $this->setName('gene:encryption-key-manager:invalidate');
        $this->setDescription('Invalidate old encryption keys');
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
            $output->writeln('<info>Run with --force to invalidate old keys. You need to have thoroughly reviewed your entire site and database before doing this.</info>');
            return Cli::RETURN_FAILURE;
        }

        try {
            /**
             * This is largely based on how the env.php is handled in
             * @see \Magento\EncryptionKey\Model\ResourceModel\Key\Change::changeEncryptionKey()
             */
            if (!$this->writer->checkIfWritable()) {
                throw new \Exception('Deployment configuration file is not writable.');
            }

            $keys = $this->deploymentConfig->get('crypt/key');
            $keys = preg_split('/\s+/s', trim((string)$keys));
            if (count($keys) <= 1) {
                throw new \Exception('Cannot invalidate when there is only one key');
            }

            $invalidatedKeys = $this->deploymentConfig->get('crypt/invalidated_key');
            $invalidatedKeys = array_filter(preg_split('/\s+/s', trim((string)$invalidatedKeys)));

            /**
             * All but the latest encryption key needs to be invalidated
             * - Wipe out the text so that its no longer a valid key
             * - keep a record of it for storing in 'crypt/invalidated_key'
             */
            $changes = false;
            foreach ($keys as $id => $key) {
                if ($id === count($keys) - 1) {
                    break; // last key needs to remain usable
                }
                if (str_starts_with($key, 'geneinvalidatedkeys')) {
                    continue; // already been invalidated
                }
                $changes = true;
                $invalidatedKeys[] = $key; // this key needs to be added to the invalidated list
                $keys[$id] = uniqid('geneinvalidatedkeys');
                if (strlen($keys[$id]) !== SODIUM_CRYPTO_AEAD_CHACHA20POLY1305_IETF_KEYBYTES) {
                    throw new \Exception('Failed to invalidate the key with an appropriate length');
                }
            }
            unset($id, $key);

            if (!$changes) {
                $output->writeln('No further keys need invalidated');
                return Cli::RETURN_SUCCESS;
            }

            $output->writeln('Writing crypt/invalidated_key to env.php');
            $encryptInvalidSegment = new ConfigData(ConfigFilePool::APP_ENV);
            $encryptInvalidSegment->set('crypt/invalidated_key', implode(PHP_EOL, $invalidatedKeys));
            $this->writer->saveConfig([$encryptInvalidSegment->getFileKey() => $encryptInvalidSegment->getData()]);

            $output->writeln('Writing crypt/key to env.php');
            $encryptSegment = new ConfigData(ConfigFilePool::APP_ENV);
            $encryptSegment->set('crypt/key', implode(PHP_EOL, $keys));
            $this->writer->saveConfig([$encryptSegment->getFileKey() => $encryptSegment->getData()]);
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
