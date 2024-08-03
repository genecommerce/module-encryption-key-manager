<?php

declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Console;

use Magento\Framework\Console\Cli;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Gene\EncryptionKeyManager\Model\ReEncryptCloudEnvKeysCommand;

class GetReEncryptedCloudEnvironmentsKeys extends Command
{
    private const INPUT_KEY_SHOW_DECRYPTED = 'show-decrypted';

    /**
     * Constructor
     *
     * @param ReEncryptCloudEnvKeysCommand $reencryptCloudEnvKeysCommand
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly ReEncryptCloudEnvKeysCommand $reencryptCloudEnvKeysCommand,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct();
    }

    /**
     * The CLI configuration
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('gene:encryption-key-manager:get-cloud-keys');
        $this->setDescription('Reencrypt cloud encrypted keys based on $_ENV variable. ' .
            'The CLI command don\'t save new values. It has to be done manually.');
        $this->setDefinition([
            new InputOption(
                self::INPUT_KEY_SHOW_DECRYPTED,
                null,
                InputOption::VALUE_NONE,
                'Whether to show decrypted values.'
            ),
        ]);
        parent::configure();
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $showDecrypted = !!$input->getOption(self::INPUT_KEY_SHOW_DECRYPTED);

        try {
            // get old encrypted, decrypted and new encrypted values
            $config = $this->reencryptCloudEnvKeysCommand->execute();

            if (!count($config)) {
                $output->writeln('<info>There is no old encrypted environment variables found</info>');
                return CLI::RETURN_SUCCESS;
            }

            $output->writeln("<info>The CLI command doesn't rewrite values. " .
                "You have to update them manually in cloud console!</info>");
            $output->writeln("<comment>Rows count: " . count($config) . "</comment>");

            foreach ($config as $name => $arr) {
                $output->writeln(str_pad('', 120, '#'));

                /** @var $arr array{value:string, newValue:string, decryptedValue:string} */
                $output->writeln("Name: {$name}");
                if ($showDecrypted) {
                    $output->writeln("Dectypted value: {$arr['decryptedValue']}");
                }
                $output->writeln("Old Encrypted Value: {$arr['value']}");
                $output->writeln("New Encrypted Value: {$arr['newValue']}");
            }

        } catch (\Exception|\Throwable $e) {
            $this->logger->critical("Something went wrong while trying to reencrypt cloud variables.", [
                'msg' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $output->writeln("<error>" . $e->getMessage() . "</error>");

            return CLI::RETURN_FAILURE;
        }

        return CLI::RETURN_SUCCESS;
    }
}
