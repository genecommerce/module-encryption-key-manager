<?php

declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Model;

use Magento\Framework\App\DeploymentConfig;

/**
 * Common states / validators for commands
 */
class EncodingHelper
{
    /**
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Return the latest key number
     *
     * @return int
     */
    public function getLatestKeyNumber(): int
    {
        try {
            $keys = preg_split('/\s+/s', trim((string)$this->deploymentConfig->get('crypt/key')));
        } catch (\Exception) {
            return 0;
        }
        return count($keys) -1;
    }

    /**
     * Validate whether the value looks like digit:digit:string
     *
     * @param string $value
     * @return bool
     */
    public function isEncryptedValue(string $value): bool
    {
        preg_match('/^\d:\d:\S+/', $value, $matches);
        return !!count($matches);
    }

    /**
     * The value can be encrypted
     *
     * @param string $encryptedValue
     * @return bool
     */
    public function isAlreadyUpdated(string $encryptedValue): bool
    {
        return str_starts_with($encryptedValue, $this->getLatestKeyNumber() . ":");
    }
}
