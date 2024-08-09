<?php

declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Model;

use Magento\Framework\Encryption\EncryptorInterface;

class RecursiveDataProcessor
{
    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var int
     */
    private $failures = 0;

    /**
     * RecursiveDataProcessor constructor.
     *
     * @param EncryptorInterface $encryptor
     */
    public function __construct(EncryptorInterface $encryptor)
    {
        $this->encryptor = $encryptor;
    }

    /**
     * Recursively process nested encrypted values.
     *
     * @param mixed $layer
     * @return mixed
     */
    public function down($layer)
    {
        foreach ($layer as $key => $value) {
            if (is_array($value) || is_object($value)) {
                // If either array or object go down a level and process it and its children
                $value = $this->down($value);
            } else {
                // Only need to process encrypted strings
                if (is_string($value)) {
                    // Previous iteration may have $matches so unset
                    unset($matches);
                    // Match on string that look encrypted (digit:digit:non_whitespace(1 or more)
                    preg_match('/\d:\d:\S+/', $value, $matches);
                    // If match found $matches[0] will exist
                    if (isset($matches[0])) {
                        // Re-encrypt value
                        $decryptedValue = $this->encryptor->decrypt($value);
                        if ($decryptedValue === '') {
                            /**
                             * \Magento\Framework\Encryption\Encryptor::decrypt seems to return '' on failure
                             */
                            $this->failures++;
                        } else {
                            $value = $this->encryptor->encrypt($decryptedValue);
                        }
                        // Remove $decryptedValue for future iterations
                        unset($decryptedValue);
                    }
                }
            }
            // Set value back to parent
            $this->setValue($layer, $key, $value);
        }
        return $layer;
    }

    /**
     * Wrapper to set value back to parent as element write syntax differs by type
     *
     * @param mixed $parent
     * @param mixed $key
     * @param mixed $value
     * @return mixed
     */
    private function setValue($parent, $key, $value)
    {
        if (is_array($parent)) {
            $parent[$key] = $value;
        } else {
            // $parent is object
            $parent->$key = $value;
        }
        return $parent;
    }

    /**
     * Did we encounter any cases were we were unable to decrypt & re-encrypt stored values
     * @return bool
     */
    public function hasFailures(): bool
    {
        return $this->failures > 0;
    }
}
