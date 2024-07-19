# module-encryption-key-manager

[![<genecommerce>](https://circleci.com/gh/genecommerce/module-encryption-key-manager.svg?style=svg)](https://circleci.com/gh/genecommerce/module-encryption-key-manager)

This module was built to aid with https://sansec.io/research/cosmicsting-hitting-major-stores

From the sansec post
> Upgrading is Insufficient
> As we warned in our earlier article, it is crucial for merchants to upgrade or apply the official isolated fix. At this stage however, just patching for the CosmicSting vulnerability is likely to be insufficient.
>
>The stolen encryption key still allows attackers to generate web tokens even after upgrading. Merchants that are currently still vulnerable should consider their encryption key as compromised. Adobe offers functionality out of the box to change the encryption key while also re-encrypting existing secrets.
>
>Important note: generating a new encryption key using this functionality does not invalidate the old key. We recommend manually updating the old key in app/etc/env.php to a new value rather than removing it.

Even with your store secured, there is the chance that a JWT was issued and may still be valid. Merchants are strongly encouraged to rotate their encryption key to be safe, and the Magento process of generating a new encryption key does not actually invalidate the old one.

This module is provided as-is without any warranty. Test this on your local instances, then staging, then production. Use at your own risk.

This module **does not conflict** with the [new hotfix](https://experienceleague.adobe.com/en/docs/commerce-knowledge-base/kb/troubleshooting/known-issues-patches-attached/security-update-available-for-adobe-commerce-apsb24-40-revised-to-include-isolated-patch-for-cve-2024-34102?#hotfix) released by Adobe. Both this module and that hotfix improve security in the same way, by making `SecretBasedJwksFactory` use the most recent key. This module also provides additional tooling and improvements, please read below.

# Installation
```
composer require gene/module-encryption-key-manager
bin/magento setup:upgrade
```

# How to Rotate your key and protect your store 

This is a rough list of steps that should be followed to prevent attacks with CosmicSting. Please read all of the steps carefully to understand the features this module provides, as well as the points of risk.

## Generate a new Key and prevent old ones from being used for JWT

This should be every merchant's **priority!** Install this module and generate a new key with: 

`php bin/magento gene:encryption-key-manager:generate`

This will force the JWT factory to use the newly generated key. Other areas of the application may continue to use the old keys. This step is the absolute priority and will help prevent attacks with CosmicSting.

## Fully rotate your old keys

1. **Review your database** for any tables with encrypted values. 
```bash
$ zgrep -h -E '0:3:' database.sql.gz | colrm 500 | grep -Eo ".{0,255}\` VALUES" | uniq | sed -e 's/INSERT.INTO..//' -e 's/..VALUES//'
admin_user
core_config_data
customer_entity
oauth_token
oauth_consumer
tfa_user_config
admin_adobe_ims_webapi
adobe_user_profile
```
2. **Review functions** using `->hash(` from the encryptor class. Changing the keys will result in a different hash.
3. If you have **custom logic** to handle that, it will be something you need to work that out manually.
3. **Generate a new key** `php bin/magento gene:encryption-key-manager:generate`
   1. `Magento\Catalog\Model\View\Asset\Image` will continue to use the key at the `0` index
   1. `Magento\JwtUserToken\Model\SecretBasedJwksFactory` will only use the most recently generated key at the highest index
4. **Fix missing config values** `php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data`
   1. Re-run to verify `php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data`
4. **Fix 2FA data** `php bin/magento gene:encryption-key-manager:reencrypt-tfa-data`
   1. Re-run to verify `php bin/magento gene:encryption-key-manager:reencrypt-tfa-data`
5. Fix up all additional identified columns like so, be careful to verify each table and column as this may not be an exhaustive list (also be aware of `entity_id`, `row_id` and `id`)
    1. `bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token`
    2. `bin/magento gene:encryption-key-manager:reencrypt-column customer_entity entity_id rp_token`
    3. `bin/magento gene:encryption-key-manager:reencrypt-column oauth_token entity_id secret`
    4. `bin/magento gene:encryption-key-manager:reencrypt-column oauth_consumer entity_id secret`
    5. `bin/magento gene:encryption-key-manager:reencrypt-column admin_adobe_ims_webapi id access_token`
    6. `bin/magento gene:encryption-key-manager:reencrypt-column adobe_user_profile id access_token`
6. At this point you should have all your data migrated to your new encryption key, to help you verify this you can do the following
   1. `php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_enable_decrypt_logging 1`
   2. `php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_only_log_old_decrypts 1`
   3. Monitor your logs for "gene encryption manager" to verify nothing is still using the old key
7. When you are happy you can **invalidate your old key** `php bin/magento gene:encryption-key-manager:invalidate`
   1. `Magento\Catalog\Model\View\Asset\Image` will continue to use the key at the `0` index in the `crypt/invalidated_key` section
6. Test, test test! Your areas of focus for testing include
- all integrations that use Magento's APIs
- your media should still be displaying with the same hash directory. If it is regenerating it would take up a large amount of disk space and runtime.
- admin user login/logout 
- customer login/logout

# Features

## Automatically invalidates old JWTs when a new key is generated
When magento generates a new encryption key it still allows the old one to be used with JWTs. This module prevents that by updating `\Magento\JwtUserToken\Model\SecretBasedJwksFactory` to only allow keys generated against the most recent encryption key.

We inject a wrapped `\Gene\EncryptionKeyManager\Model\DeploymentConfig` which only returns the most recent encryption key. This means that any existing tokens are no longer usable when a new encryption key is generated.

## Allows you to keep your existing media cache directories
When magento generates a new encryption key, it causes the product media cache hash to change. This causes all product media to regenerate which takes a lot of processing time which can slow down page loads for your customers, as well as consuming extra disk space. This module ensures the old hash is still used for the media gallery.

Magento stores resized product images in directories like `media/catalog/product/cache/abc123/f/o/foobar.jpg`, the hash `abc123` is generated utilising the encryption keys in the system.

To avoid having to regenerate all the product media when cycling the encryption key there are some changes to force it to continue using the original value.

`Magento\Catalog\Model\View\Asset\Image` has the `$encryptor` swapped out with `Gene\EncryptionKeyManager\Service\InvalidatedKeyHasher`. Which allows you to continue to generate md5 hashes with the old key.

## Prevents long running process updating order payments
This module will also fix an issue where every `sales_order_payment` entry was updated during the key generation process. On large stores this could take a long time. Now only necessary entries with saved card information are updated.

## Logging

This module provides a mechanism to log the source for each call to decrypt. This will produce a lot of data as the magento system configuration is encrypted so each request will trigger a log write.

It is recommended to enable this logging after you have handled the re-encryption of all data in your system, but _before_ you invalidate the old keys. This will give you an indication that you have properly handled everything, because if you see a log written it will tell you that something has been missed and where to go to find the source of the issue.

```bash
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_enable_decrypt_logging 1
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_only_log_old_decrypts 1
```

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

## bin/magento gene:encryption-key-manager:reencrypt-column

This allows you to target a specific column for re-encryption.

This command runs in dry run mode by default, do that as a first pass to see the changes that will be made. When you are happy run with `--force`.

You should identify all columns that need to be handled, and run them through this process.

```bash
$ bin/magento gene:encryption-key-manager:reencrypt-column customer_entity entity_id rp_token
Run with --force to make these changes, this will run in dry-run mode by default
The latest encryption key is number 1, looking for old entries
Looking for 'rp_token' in 'customer_entity', identified by 'entity_id'
########################################################################################################################
entity_id: 9876
ciphertext_old: 0:3:54+QHWqhSwuncAa87Ueph7xF9qPL1CT6+M9Z5AWuup447J33KGVw+Q+BvVLSKR1H1umiq69phKq5NEHk
plaintext: acb123
ciphertext_new: 1:3:Y52lxB2VDnKeOHa0hf7kG/d15oooib6GQOYTcAmzfuEnhfW64NAdNN4YjRrhlh2IzQBO5IbwS48JDDRh
Dry run mode, no changes have been made
########################################################################################################################
Done
```

## bin/magento gene:encryption-key-manager:reencrypt-tfa-data

This command re-encrypts the 2FA data stored in `tfa_user_config`. Some of this data is doubly encrypted, which is why it needs special handling. 

This command runs in dry run mode by default, do that as a first pass to see the changes that will be made. When you are happy run with `--force`.

This CLI has only been tested with Google Authenticator (TOTP) and U2F (Yubikey, etc). If you use Authy or DUO you *MUST* verify before use.

```bash
$ bin/magento gene:encryption-key-manager:reencrypt-tfa-data
Run with --force to make these changes, this will run in dry-run mode by default
This CLI has only been tested with Google Authenticator (TOTP) and U2F (Yubikey, etc). If you use Authy or DUO you *MUST* verify before use.
The latest encryption key is number 1, looking for old entries
Looking for encoded_config in tfa_user_config, identified by 'config_id'
########################################################################################################################
config_id: 1
ciphertext_old: 0:3:rV/z9+ilmOtaPGnOoZBayZ3waBNphK1RAcyWLetipM5UONn793rTyRknO1GhWxKxXC3ooJAgWDTMJPaXGRMGdj8yOqrlrjEp9uqi8D9SFgE/UTiWkBF4RRwVvZeo4lGGnll/CxJmtzuMXWa65TS0Z/a2QLdPyIH/3OomJH7sb3FgfQ==
plaintext: {"google":{"secret":"0:3:LKm9642Rpl0gqlBha+m3FYWnQBBtLgjdLDvjfoPo923xmxd9ykbnvX0LucKI","active":true}}
plaintext_new: {"google":{"secret":"1:3:m9mScDkTkeCdn2lXpwf5oMkL7lmgLOTYJXQyKbK\/m8QwDZVDNWI3CzH+uBaq","active":true}}
ciphertext_new: 1:3:Tw/5ik2meBqzL8oodrudxmksrOekA/DbZE5+KgBAygFxp6Zx/A7vbMyHt4+N1MtQhlnqW/mAXL3l2kDpFHIQVvi2L+23o9mRpii2ldBwmuZgDlpQsm+Q4Hf8a+t2aUKndGOMeoH6xcZXFCConC+TUI+uregFXx6B5LU4ohCY52m/v7w=
Dry run mode, no changes have been made
########################################################################################################################
Done
```
