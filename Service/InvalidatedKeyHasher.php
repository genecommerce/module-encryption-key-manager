<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Service;

use Magento\Framework\Encryption\Encryptor as MageEncryptor;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Encryption\KeyValidator;
use Magento\Framework\Math\Random;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * This class is injected into places where the 'hash' function is needed alongside the old keys
 *
 * Magento\Catalog\Model\View\Asset\Image
 * - So that we do not have to regenerate product media when we invalidate the previous encryption key
 */
class InvalidatedKeyHasher extends MageEncryptor
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param Random $random
     * @param DeploymentConfig $deploymentConfig
     * @param ScopeConfigInterface $scopeConfig
     * @param KeyValidator|null $keyValidator
     */
    public function __construct(
        Random $random,
        DeploymentConfig $deploymentConfig,
        ScopeConfigInterface $scopeConfig,
        KeyValidator $keyValidator = null
    ) {
        parent::__construct($random, $deploymentConfig, $keyValidator);
        $this->scopeConfig = $scopeConfig;

        $keyIndex = $this->scopeConfig->getValue('gene/encryption_key_manager/invalidated_key_index');
        if ($keyIndex === null) {
            return;
        }
        $this->keyVersion = (int) $keyIndex;

        $invalidatedKeys = array_filter(preg_split('/\s+/s', trim((string)$deploymentConfig->get('crypt/invalidated_key'))));
        if (!empty($invalidatedKeys)) {
            $this->keys = $invalidatedKeys;
        }
    }

    /**
     * @throws \LogicException
     */
    private function fail()
    {
        throw new \LogicException('You can only use this class for the "hash" function with invalidated keys');
    }

    /**
     * @inheritdoc
     * @throws \LogicException
     */
    public function getHash($password, $salt = false, $version = self::HASH_VERSION_LATEST)
    {
        return $this->fail();
    }

    /**
     * @inheritdoc
     * @throws \LogicException
     */
    public function isValidHash($password, $hash)
    {
        return $this->fail();
    }

    /**
     * @inheritdoc
     * @throws \LogicException
     */
    public function validateHashVersion($hash, $validateCount = false)
    {
        return $this->fail();
    }

    /**
     * @inheritdoc
     * @throws \LogicException
     */
    public function encrypt($data)
    {
        return $this->fail();
    }

    /**
     * @inheritdoc
     * @throws \LogicException
     */
    public function decrypt($data)
    {
        return $this->fail();
    }
}
