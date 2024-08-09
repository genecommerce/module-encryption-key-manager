#!/bin/bash
set -euo pipefail
err_report() {
    echo "Error on line $1"
    echo "last test.txt was"
    cat test.txt
    echo "last app/etc/env.php was"
    cat app/etc/env.php
    echo "restoring original env.php"
    cp app/etc/env.php.bak app/etc/env.php
}
trap 'err_report $LINENO' ERR
echo "backing up initial app/etc/env.php"
rm -rf var/cache
cp app/etc/env.php app/etc/env.php.bak
php bin/magento app:config:import

URL='http://0.0.0.0:1234/'
CURRENT_TIMESTAMP=$(date +%s)
ADMIN="adminuser$CURRENT_TIMESTAMP"
PASSWORD='password123'

echo "Putting magento into production mode, di:compile has already been generated"
php bin/magento deploy:mode:set production -s
php -d memory_limit=-1 bin/magento setup:static-content:deploy en_US --no-interaction -f --no-ansi --area=frontend

echo "Verifying frontend is functional"
php bin/magento cache:disable full_page
curl "$URL" -vvv > test.txt 2>&1
grep -q '200 OK' test.txt
grep --max-count=1  'static/version' test.txt
grep -q 'All rights reserved.' test.txt
echo "PASS"
echo "";echo "";

echo "Stubbing in some test data"
vendor/bin/n98-magerun2 --version
vendor/bin/n98-magerun2 admin:user:create --no-interaction --admin-user "$ADMIN" --admin-email "example$CURRENT_TIMESTAMP@example.com" --admin-password $PASSWORD --admin-firstname adminuser --admin-lastname adminuser
vendor/bin/n98-magerun2 config:store:set zzzzz/zzzzz/zzzz xyz123 --encrypt

echo "Spoofing an encrypted config value into env.php"
ENCRYPTED_ENV_VALUE=$(vendor/bin/n98-magerun2 dev:encrypt 'Some Base Name')
bin/magento config:set --lock-env general/store_information/name "$ENCRYPTED_ENV_VALUE"

ADMIN_ID=$(vendor/bin/n98-magerun2 db:query "SELECT user_id FROM admin_user LIMIT 1")
ADMIN_ID="${ADMIN_ID: -1}"
FAKE_GOOGLE_TOKEN=$(vendor/bin/n98-magerun2 dev:encrypt 'googletokenabc123')
TWOFA_JSON="{\"google\":{\"secret\":\"$FAKE_GOOGLE_TOKEN\",\"active\":true}}"
TWOFA_JSON_ENCRYPTED=$(vendor/bin/n98-magerun2 dev:encrypt "$TWOFA_JSON")
echo "Generating 2FA data for admin user $ADMIN in tfa_user_config"
vendor/bin/n98-magerun2 db:query "delete from tfa_user_config where user_id=$ADMIN_ID";
vendor/bin/n98-magerun2 db:query "insert into tfa_user_config(user_id, encoded_config) values ($ADMIN_ID, '$TWOFA_JSON_ENCRYPTED');"
vendor/bin/n98-magerun2 db:query "select user_id, encoded_config from tfa_user_config where user_id=$ADMIN_ID";

FAKE_RP_TOKEN=$(vendor/bin/n98-magerun2 dev:encrypt 'abc123')
vendor/bin/n98-magerun2 db:query "update admin_user set rp_token='$FAKE_RP_TOKEN' where username='$ADMIN'"
echo "Generated FAKE_RP_TOKEN=$FAKE_RP_TOKEN and assigned to $ADMIN"

echo "Generating a fake json column"
FAKE_JSON_PASSWORD=$(vendor/bin/n98-magerun2 dev:encrypt 'jsonpasswordabc123')
FAKE_JSON_USERNAME=$(vendor/bin/n98-magerun2 dev:encrypt 'foobar')
FAKE_JSON_PAYLOAD="{\"username\": \"$FAKE_JSON_USERNAME\", \"password\": \"$FAKE_JSON_PASSWORD\", \"request_url\": \"\"}"
vendor/bin/n98-magerun2 db:query 'DROP TABLE IF EXISTS fake_json_table; CREATE TABLE fake_json_table (id INT AUTO_INCREMENT PRIMARY KEY, text_column TEXT);'
vendor/bin/n98-magerun2 db:query "insert into fake_json_table(text_column) values ('$FAKE_JSON_PAYLOAD');"
vendor/bin/n98-magerun2 db:query "select * from fake_json_table";

echo "";echo "";

echo "Verifying commands need to use --force"

php bin/magento gene:encryption-key-manager:generate > test.txt || true;
if grep -q 'Run with --force' test.txt; then
    echo "PASS: generate needs to run with force"
else
    echo "FAIL: generate needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:invalidate > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: invalidate needs to run with force"
else
    echo "FAIL: invalidate needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: reencrypt-unhandled-core-config-data needs to run with force"
else
    echo "FAIL: reencrypt-unhandled-core-config-data needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:reencrypt-tfa-data > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: reencrypt-tfa-data needs to run with force"
else
    echo "FAIL: reencrypt-tfa-data needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: reencrypt-column needs to run with force"
else
    echo "FAIL: reencrypt-column needs to run with force" && false
fi
echo "";echo "";

echo "Verifying you cannot invalidate with only 1 key"
php bin/magento gene:encryption-key-manager:invalidate --force > test.txt || true
if grep -Eq 'Cannot invalidate when there is only one key|No further keys need invalidated' test.txt; then
    echo "PASS: You cannot invalidate with only 1 key"
else
    echo "FAIL" && false
fi
echo "";echo "";

echo "Generating a new encryption key"
grep -q "$ENCRYPTED_ENV_VALUE" app/etc/env.php
php bin/magento gene:encryption-key-manager:generate --force  > test.txt
if grep -q "$ENCRYPTED_ENV_VALUE" app/etc/env.php; then
    echo "FAIL: The old encrypted value in env.php was not updated" && false
fi
grep "'name'" app/etc/env.php | grep -q "1:3:"
grep -q '_reEncryptSystemConfigurationValues - start' test.txt
grep -q '_reEncryptSystemConfigurationValues - end'   test.txt
grep -q '_reEncryptCreditCardNumbers - start' test.txt
grep -q '_reEncryptCreditCardNumbers - end'   test.txt
echo "PASS"
echo "";echo "";

echo "Generating a new encryption key - skipping _reEncryptCreditCardNumbers"
php bin/magento gene:encryption-key-manager:generate --force --skip-saved-credit-cards > test.txt
grep "'name'" app/etc/env.php | grep -q "2:3:"
grep -q '_reEncryptSystemConfigurationValues - start' test.txt
grep -q '_reEncryptSystemConfigurationValues - end'   test.txt
grep -q '_reEncryptCreditCardNumbers - skipping' test.txt
if grep -q '_reEncryptCreditCardNumbers - start' test.txt; then
    echo "FAIL: We should never start on _reEncryptCreditCardNumbers with --skip-saved-credit-cards" && false
fi
if grep -q '_reEncryptCreditCardNumbers - end' test.txt; then
    echo "FAIL: We should never end on _reEncryptCreditCardNumbers with --skip-saved-credit-cards" && false
fi
echo "PASS"
echo "";echo "";

echo "Verifying frontend is still functional after key generation"
php bin/magento cache:flush
curl "$URL?test1" -vvv > test.txt 2>&1
grep -q '200 OK' test.txt
grep --max-count=1  'static/version' test.txt
grep -q 'All rights reserved.' test.txt
echo "PASS"
echo "";echo "";

echo "Running reencrypt-unhandled-core-config-data"
php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data --force > test.txt
cat test.txt
grep -q 'zzzzz/zzzzz/zzzz' test.txt
grep -q 'xyz123' test.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-unhandled-core-config-data - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running reencrypt-tfa-data"
php bin/magento gene:encryption-key-manager:reencrypt-tfa-data --force > test.txt
cat test.txt
grep 'plaintext_new' test.txt | grep 'secret' test.txt
if grep 'plaintext_new' test.txt | grep "$TWOFA_JSON_ENCRYPTED"; then
    echo "FAIL: The plaintext_new should no longer have the original TWOFA_JSON_ENCRYPTED data" && false
else
    echo "PASS: The plaintext_new should no longer have the original TWOFA_JSON_ENCRYPTED data"
fi
echo "PASS"
echo "";echo "";
echo "Running reencrypt-tfa-data - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-tfa-data --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running reencrypt-column"
php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token --force > test.txt
cat test.txt
grep -q "$FAKE_RP_TOKEN" test.txt
grep -q abc123 test.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-column - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running reencrypt-column on JSON column username"
php bin/magento gene:encryption-key-manager:reencrypt-column fake_json_table id text_column.username --force > test.txt
cat test.txt
grep -q "$FAKE_JSON_USERNAME" test.txt
grep -q foobar test.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-column on JSON column username - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-column fake_json_table id text_column.username --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running reencrypt-column on JSON column password to validate multiple fields can be re-encrypted"
php bin/magento gene:encryption-key-manager:reencrypt-column fake_json_table id text_column.password --force > test.txt
cat test.txt
grep -q "$FAKE_JSON_PASSWORD" test.txt
grep -q jsonpasswordabc123 test.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-column on JSON column password to validate multiple fields can be re-encrypted - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-column fake_json_table id text_column.password --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running invalidate"
php bin/magento gene:encryption-key-manager:invalidate --force
grep -q invalidated_key app/etc/env.php
php bin/magento gene:encryption-key-manager:invalidate --force | grep --context 999 'No further keys need invalidated'
echo "PASS"
echo "";echo "";

echo "Testing the decrypt logger"

echo "Testing disable behaviour"
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_enable_decrypt_logging 0
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_only_log_old_decrypts 0
php bin/magento cache:flush; php bin/magento | head -1; # clear and warm caches
rm -f var/log/*.log && php bin/magento | head -1 # trigger a decrypt of the stored system config
touch var/log/gene_encryption_key.log
ls -l var/log
if grep -q 'gene encryption manager' var/log/gene_encryption_key.log; then
    cat var/log/gene_encryption_key.log
    echo "FAIL: No logs should be produced without enabling the logger" && false
else
    echo "PASS: No logs were produced"
fi
echo "";echo "";

echo "Testing that enabling it produces a log"
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_enable_decrypt_logging 1
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_only_log_old_decrypts 0
php bin/magento cache:flush; php bin/magento | head -1; # clear and warm caches
rm -f var/log/*.log && php bin/magento | head -1 # trigger a decrypt of the stored system config
touch var/log/gene_encryption_key.log
ls -l var/log
if grep 'gene encryption manager' var/log/gene_encryption_key.log | grep -q 'Magento\\'; then
    echo "PASS: A log was produced"
else
    cat var/log/gene_encryption_key.log
    echo "FAIL: A log should be produced" && false
fi
echo "";echo "";

echo "Testing that gene_encryption_manager_only_log_old_decrypts=1 stops a log being written"
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_only_log_old_decrypts 1
php bin/magento cache:flush; php bin/magento | head -1; # clear and warm caches
rm -f var/log/*.log && php bin/magento | head -1 # trigger a decrypt of the stored system config
touch var/log/gene_encryption_key.log
ls -l var/log
if grep -q 'gene encryption manager' var/log/gene_encryption_key.log; then
    cat var/log/gene_encryption_key.log
    echo "FAIL: No logs should be produced when the keys are up to date" && false
else
    echo "PASS: No logs were produced when the keys were up to date"
fi
echo "";echo "";

echo "Testing that gene_encryption_manager_only_log_old_decrypts=1 writes when an old key is used"
rm -f var/log/*.log && php bin/magento | head -1 # trigger a decrypt of the stored system config
vendor/bin/n98-magerun2 dev:decrypt '0:3:qwertyuiopasdfghjklzxcvbnm' # we are on a higher key than 0 now
touch var/log/gene_encryption_key.log
ls -l var/log
if grep 'gene encryption manager' var/log/gene_encryption_key.log | grep -q 'DecryptCommand'; then
    echo "PASS: We have a log hit when trying to decrypt with the old key"
else
    cat var/log/gene_encryption_key.log
    echo "FAIL: We should have a log hit when trying to decrypt using an old key" && false
fi
echo "";echo "";

echo "Verifying that the log is not present in system.log"
touch var/log/system.log
if grep 'gene encryption manager' var/log/system.log | grep -q 'DecryptCommand'; then
    cat var/log/system.log
    echo "FAIL: The log is also present in system.log" && false
else
    echo "PASS: The log is not present in system.log"
fi

echo "Verifying frontend is still functional after all the tests"
php bin/magento cache:flush
curl "$URL?test2" -vvv > test.txt 2>&1
grep -q '200 OK' test.txt
grep --max-count=1  'static/version' test.txt
grep -q 'All rights reserved.' test.txt
echo "PASS"
echo "";echo "";

echo "A peek at an example log"
grep 'gene encryption manager' var/log/gene_encryption_key.log | tail -1

echo "A peek at the env.php"
grep "'name'" app/etc/env.php
grep -A10 "'crypt' =>" app/etc/env.php
echo "";echo "";

echo "restoring original env.php"
cp app/etc/env.php.bak app/etc/env.php
echo "DONE"
