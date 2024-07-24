<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Console;

use Gene\EncryptionKeyManager\Service\ChangeEncryptionKey as ChangeEncryptionKeyService;
use Gene\EncryptionKeyManager\Service\ReencryptEnvSystemConfigurationValues;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\Encryptor;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Console\Cli;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Framework\App\State;

class GenerateEncryptionKey extends Command
{
    public const INPUT_KEY_FORCE = 'force';
    public const INPUT_SKIP_SAVED_CREDIT_CARDS = 'skip-saved-credit-cards';
    public const INPUT_KEY_KEY = 'key';
    public const INPUT_KEY_KEY_SHORTCUT = 'k';

    /**
     * @param ChangeEncryptionKeyService $changeEncryptionKey
     * @param CacheInterface $cache
     * @param ScopeConfigInterface $scopeConfig
     * @param WriterInterface $configWriter
     * @param Emulation $emulation
     * @param State $state
     * @param Encryptor $encryptor
     */
    public function __construct(
        private readonly ChangeEncryptionKeyService $changeEncryptionKey,
        private readonly CacheInterface $cache,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly WriterInterface $configWriter,
        private readonly Emulation $emulation,
        private readonly State $state,
        private readonly Encryptor $encryptor,
        private readonly ReencryptEnvSystemConfigurationValues $reencryptEnvSystemConfigurationValues
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
            new InputOption(
                self::INPUT_SKIP_SAVED_CREDIT_CARDS,
                null,
                InputOption::VALUE_NONE,
                'Whether to skip encrypting the sales_order_payment cc_number_enc data'
            ),
            new InputOption(
                self::INPUT_KEY_KEY,
                self::INPUT_KEY_KEY_SHORTCUT,
                InputOption::VALUE_OPTIONAL,
                'The new crypt key to use for re-encryption (32 chars). If not set, the new key will be generated'
            )
        ];

        $this->setName('gene:encryption-key-manager:generate');
        $this->setDescription('Generate a new encryption key');
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
        $newKey = null;
        if ($input->getOption(self::INPUT_KEY_KEY)) {
            $newKey = $input->getOption(self::INPUT_KEY_KEY);
            $output->writeln('<info>The provided crypt key will be used for re-encryption.</info>');
        } else {
            $output->writeln('<info>A new key will be generated for re-encryption, use "--key" to specify a custom key.</info>');
        }

        if (!$input->getOption(self::INPUT_KEY_FORCE)) {
            $output->writeln('<info>Run with --force to generate a new key. This will decrypt and reencrypt values in core_config_data and saved credit card info</info>');
            return Cli::RETURN_FAILURE;
        }

        try {
            $countOfKeys = count(explode(PHP_EOL, $this->encryptor->exportKeys()));
            $output->writeln("The system currently has $countOfKeys keys");

            /**
             * This is heavily based on the below
             *
             * @see \Magento\EncryptionKey\Controller\Adminhtml\Crypt\Key\Save::execute()
             */
            try {
                $this->state->setAreaCode('adminhtml');
            } catch (\Magento\Framework\Exception\LocalizedException $exception) {
                // Area code is already set
            }
            $this->emulation->startEnvironmentEmulation(0, 'adminhtml');
            $output->writeln('Generating a new encryption key using the magento core class');
            $this->changeEncryptionKey->setOutput($output);
            $this->changeEncryptionKey->setSkipSavedCreditCards(
                (bool)$input->getOption(self::INPUT_SKIP_SAVED_CREDIT_CARDS)
            );
            $this->changeEncryptionKey->changeEncryptionKey($newKey);
            $output->writeln('reEncryptEnvConfigurationValues - start');
            $this->reencryptEnvSystemConfigurationValues->execute();
            $output->writeln('reEncryptEnvConfigurationValues - end');
            $this->emulation->stopEnvironmentEmulation();
            $output->writeln('Cleaning cache');

            $value = $this->scopeConfig->getValue('gene/encryption_key_manager/invalidated_key_index');
            if ($value == null) {
                $this->configWriter->save(
                    'gene/encryption_key_manager/invalidated_key_index',
                    (int) $countOfKeys - 1
                );
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
