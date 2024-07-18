<?php declare(strict_types=1);

namespace Gene\EncryptionKeyManager\Model;

use Magento\Framework\Encryption\EncryptorInterface;

class RecursiveDataProcessor
{
    public function __construct(
        private readonly EncryptorInterface $encryptor,
    ) {}

    /**
     * Recursively process nested encrypted values
     * @param $layer
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
                        $value = $this->encryptor->decrypt($value);
                        $value = $this->encryptor->encrypt($value);
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
     * @param $parent
     * @param $key
     * @param $value
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
}
