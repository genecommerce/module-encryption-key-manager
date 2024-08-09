<?php

declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Model;

use Magento\Config\Model\Placeholder\PlaceholderFactory;
use Magento\Config\Model\Placeholder\PlaceholderInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;

class ReEncryptCloudEnvKeysCommand
{
    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var EncodingHelper
     */
    private $helper;

    /**
     * @var PlaceholderInterface
     */
    private $placeholder;

    /**
     * Constructor
     *
     * @param EncryptorInterface $encryptor
     * @param EncodingHelper $helper
     * @param PlaceholderFactory $placeholderFactory
     * @throws LocalizedException
     */
    public function __construct(
        EncryptorInterface $encryptor,
        EncodingHelper $helper,
        PlaceholderFactory $placeholderFactory
    ) {
        $this->encryptor = $encryptor;
        $this->helper = $helper;
        $this->placeholder = $placeholderFactory->create(PlaceholderFactory::TYPE_ENVIRONMENT);
    }

    /**
     * Execute the command
     *
     * @param array|null $environmentVariables
     * @return array
     * @throws \Exception
     */
    public function execute(array $environmentVariables = null): array
    {
        if ($environmentVariables === null) {
            if (!isset($_ENV)) {
                throw new \Exception("No environment variables defined");
            }
            $environmentVariables = $_ENV;
        }

        $config = [];

        foreach ($environmentVariables as $template => $value) {
            if (!$this->placeholder->isApplicable($template)
                || !$this->helper->isEncryptedValue($value)
                || $this->helper->isAlreadyUpdated($value)) {
                continue;
            }

            $decryptedValue = $this->encryptor->decrypt($value);
            $newValue = $this->encryptor->encrypt($decryptedValue);
            $config[$template] = compact('value', 'newValue', 'decryptedValue');
        }

        return $config;
    }
}
