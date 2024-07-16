# module-encryption-key-manager

This module was built to aid with https://sansec.io/research/cosmicsting-hitting-major-stores

From the sansec post
> Upgrading is Insufficient
> As we warned in our earlier article, it is crucial for merchants to upgrade or apply the official isolated fix. At this stage however, just patching for the CosmicSting vulnerability is likely to be insufficient.
>
>The stolen encryption key still allows attackers to generate web tokens even after upgrading. Merchants that are currently still vulnerable should consider their encryption key as compromised. Adobe offers functionality out of the box to change the encryption key while also re-encrypting existing secrets.
>
>Important note: generating a new encryption key using this functionality does not invalidate the old key. We recommend manually updating the old key in app/etc/env.php to a new value rather than removing it.

Even with your store secured, there is the chance that a JWT was issued and may still be valid. It makes sense to cycle your encryption key just to be safe.

Additionally, the Magento process of generating a new encryption key does not actually invalidate the old one, this issue needs addressed.

# Summary

This module is provided as-is without any warranty. Test this on your local instances, then staging, then production. Use at your own risk.

Vanilla Magento has a few gaps which are fixed by this module
- Security - When magento generates a new encryption key it still allows the old one to be used with JWTs. This module prevents that.
- Performance
  -  When magento generates a new encryption key, it causes the product media cache hash to change. This causes all product media to regenerate which takes a lot of processing time which can slow down page loads for your customers, as well as consuming extra disk space. This module ensures the old hash is still used for the media gallery.
  -  This module fixes an issue where every `sales_order_payment` entry was updated during the key generation process. On large stores this could take a long time. Now only necessary entries with saved card information are updated.

As well as providing these fixes there is also additional CLI tooling to help you review, and eventually invalidate your old keys.

# Process to generate a new key and prevent JWTs from old keys being used

The initial mitigation can be to generate a new key, and ensure only this key is valid for JWTs

1. Generate a new key `php bin/magento gene:encryption-key-manager:generate`
   1. `Magento\Catalog\Model\View\Asset\Image` will continue to use the key at the `0` index
   1. `Magento\JwtUserToken\Model\SecretBasedJwksFactory` will only use the most recently generated key at the highest index

# Process to fully cycle your encryption key

Read all of this document carefully to understand the features this module provides, as well as the points of risk.

1. Review your database for any tables with encrypted values. 
```bash
$ zgrep -h -E '0:3:' database.sql.gz | colrm 500 | grep -Eo ".{0,255}\` VALUES" | uniq | sed -e 's/INSERT.INTO..//' -e 's/..VALUES//'
core_config_data
oauth_token
yotpo_sync_queue
```
2. Review any functions using `->hash(` from the encryptor class. Changing the keys will result in a different hash.
3. If you have custom logic to handle that will be something you need to work that out manually.
3. Generate a new key `php bin/magento gene:encryption-key-manager:generate`
   1. `Magento\Catalog\Model\View\Asset\Image` will continue to use the key at the `0` index
   1. `Magento\JwtUserToken\Model\SecretBasedJwksFactory` will only use the most recently generated key at the highest index
4. Fix up any missing config values `php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data`
   1. Re-run to verify `php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data`
5. When you are happy you can invalidate your old key `php bin/magento gene:encryption-key-manager:invalidate`
   1. `Magento\Catalog\Model\View\Asset\Image` will continue to use the key at the `0` index in the `crypt/invalidated_key` section

At this point you should test
- all integrations
- that your media is still displaying with the same hash directory, if it is regenerating it would take up a large amount of disk space and runtime.
- admin user login/logout 
- customer login/logout

# Features

## Automatically invalidates old JWTs when a new key is generated

It updates `\Magento\JwtUserToken\Model\SecretBasedJwksFactory` to only allow keys generated against the most recent encryption key.

We inject a wrapped `\Gene\EncryptionKeyManager\Model\DeploymentConfig` which only returns the most recent encryption key. This means that any existing tokens are no longer usable when a new encryption key is generated.

## Allows you to keep your existing media cache directories

Magento stores resized product images in directories like `media/catalog/product/cache/abc123/f/o/foobar.jpg`, the hash `abc123` is generated utilising the encryption keys in the system.

To avoid having to regenerate all the product media when cycling the encryption key there are some changes to force it to continue using the original value.

`Magento\Catalog\Model\View\Asset\Image` has the `$encryptor` swapped out with `Gene\EncryptionKeyManager\Service\InvalidatedKeyHasher`. Which allows you to continue to generate md5 hashes with the old key.

## bin/magento gene:encryption-key-manager:generate

You can use `php bin/magento gene:encryption-key-manager:generate` to generate a new encryption key

This CLI tool does the same tasks as `\Magento\EncryptionKey\Controller\Adminhtml\Crypt\Key\Save::execute()` with a few tweaks
- Avoids unneeded manipulation of empty `sales_order_payment` `cc_number_enc` values. This can he helpful on large stores with many items in this table.

```bash
$ php bin/magento gene:encryption-key-manager:generate --force
Generating a new encryption key
_reEncryptSystemConfigurationValues - start
_reEncryptSystemConfigurationValues - end
_reEncryptCreditCardNumbers - start
_reEncryptCreditCardNumbers - end
Cleaning cache
Done
```

## bin/magento gene:encryption-key-manager:invalidate

You can use `php bin/magento gene:encryption-key-manager:invalidate` to invalidate old keys

This will create a new section to store the old `invalidated_key` within your `env.php` as well as stub out the `crypt/key` path with nonsense text, so that the numerical ordering of the keys is maintained.

```diff
--- app/etc/env.php   2024-07-14 06:03:14.194370013 +0000
+++ app/etc/env.php   2024-07-14 06:04:12.775458013 +0000
@@ -50,9 +50,11 @@
         'table_prefix' => ''
     ],
     'crypt' => [
-        'key' => 'f00e29e230c723afbdaef0fb5d3e6134
-d59b93bf844ebe700ae8202f67e56e34
+        'key' => 'geneinvalidatedkeys669368519467b
+geneinvalidatedkeys6693685194682
 412b0ad1190572ff9f3c58f595ed1f3e',
+        'invalidated_key' => 'f00e29e230c723afbdaef0fb5d3e6134
+d59b93bf844ebe700ae8202f67e56e34'
     ],
     'resource' => [
         'default_setup' => [

```

## bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data

When Magento generates a new encryption key it re-encrypts values in `core_config_data` where the `backend_model` is defined as `Magento\Config\Model\Config\Backend\Encrypted`. It is likely some third party modules have not implemented this correctly and handled the decryption themselves. In these cases we need to force through the re-encryption process for them.

This command runs in dry run mode by default, do that as a first pass to see the changes that will be made. When you are happy run with `--force`.

```bash
$ php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data
Run with --force to make these changes, this will run in dry-run mode by default
The latest encryption key is number 14, looking for old entries
################################################################################
config_id: 1347
scope: default
scope_id: 0
path: yotpo/settings/secret
updated_at: 2023-08-31 12:48:27
ciphertext_old: 0:2:abc123
plaintext: some_secret_here
ciphertext_new: 14:3:xyz456
Dry run mode, no changes have been made
################################################################################
Done
```

