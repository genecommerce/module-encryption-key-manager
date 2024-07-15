<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Model;

use Magento\Framework\App\DeploymentConfig as MageDeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;

class DeploymentConfig extends MageDeploymentConfig
{
    /**
     * Gets data from flattened data
     *
     * Modified so that we only return the last entry for "crypt/key" so that the JWT process only takes the recent one
     *
     * @param string $key
     * @param mixed $defaultValue
     * @return mixed|null
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function get($key = null, $defaultValue = null)
    {
        $data = parent::get($key, $defaultValue);

        if ($key === 'crypt/key') {
            /**
             * Only allow the last key to be used for JWT
             *
             * @see \Magento\JwtUserToken\Model\SecretBasedJwksFactory::__construct
             */
            $keys = preg_split('/\s+/s', trim((string)$data));
            $data = end($keys);
        }

        return $data;
    }
}
