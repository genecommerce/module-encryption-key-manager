<?php

declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Model;

use Magento\Framework\App\DeploymentConfig;

/**
 * Common states / validators for commands.
 */
class EncodingHelper
{
    /**
     * @var DeploymentConfig
     */
    private $deploymentConfig;

    /**
     * EncodingHelper constructor.
     *
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(DeploymentConfig $deploymentConfig)
    {
        $this->deploymentConfig = $deploymentConfig;
    }

    /**
     * Return the latest key number.
     *
     * @return int
     */
    public function getLatestKeyNumber(): int
    {
        try {
            $keys = preg_split('/\s+/s', trim((string)$this->deploymentConfig->get('crypt/key')));
        } catch (\Exception $e) {
            return 0;
        }

        return is_array($keys) ? count($keys) - 1 : 0;
    }

    /**
     * Validate whether the value looks like digit:digit:string.
     *
     * @param string $value
     * @return bool
     */
    public function isEncryptedValue(string $value): bool
    {
        return (bool)preg_match('/^\d:\d:\S+/', $value);
    }

    /**
     * Returns whether the value is already encrypted.
     *
     * @param string $encryptedValue
     * @return bool
     */
    public function isAlreadyUpdated(string $encryptedValue): bool
    {
        return strpos($encryptedValue, $this->getLatestKeyNumber() . ":") === 0;
    }
}
