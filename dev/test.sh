#!/bin/bash
set -euo pipefail
err_report() {
    echo "Error on line $1"
}
trap 'err_report $LINENO' ERR

URL='http://0.0.0.0:1234/'
CURRENT_TIMESTAMP=$(date +%s)
ADMIN="adminuser$CURRENT_TIMESTAMP"
PASSWORD='password123'

echo "Stubbing in some test data"
vendor/bin/n98-magerun2 --version
vendor/bin/n98-magerun2 admin:user:create --no-interaction --admin-user "$ADMIN" --admin-email "example$CURRENT_TIMESTAMP@example.com" --admin-password $PASSWORD --admin-firstname adminuser --admin-lastname adminuser
vendor/bin/n98-magerun2 config:store:set zzzzz/zzzzz/zzzz xyz123 --encrypt
vendor/bin/n98-magerun2 config:store:set dev/debug/debug_logging 1
FAKE_RP_TOKEN=$(vendor/bin/n98-magerun2 dev:encrypt 'abc123')
vendor/bin/n98-magerun2 db:query "update admin_user set rp_token='$FAKE_RP_TOKEN' where username='$ADMIN'"
echo "Generated FAKE_RP_TOKEN=$FAKE_RP_TOKEN and assigned to $ADMIN"

echo "";echo "";

echo "Verifying commands need to use --force"

php bin/magento gene:encryption-key-manager:generate > test.txt || true;
if grep -q 'Run with --force' test.txt; then
    echo "PASS: generate needs to run with force"
else
    cat test.txt
    echo "FAIL: generate needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:invalidate > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: invalidate needs to run with force"
else
    cat test.txt
    echo "FAIL: invalidate needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: reencrypt-unhandled-core-config-data needs to run with force"
else
    cat test.txt
    echo "FAIL: reencrypt-unhandled-core-config-data needs to run with force" && false
fi

php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token > test.txt || true
if grep -q 'Run with --force' test.txt; then
    echo "PASS: reencrypt-column needs to run with force"
else
    cat test.txt
    echo "FAIL: reencrypt-column needs to run with force" && false
fi
echo "";echo "";

echo "Verifying you cannot invalidate with only 1 key"
php bin/magento gene:encryption-key-manager:invalidate --force > test.txt || true
if grep -Eq 'Cannot invalidate when there is only one key|No further keys need invalidated' test.txt; then
    echo "PASS: You cannot invalidate with only 1 key"
else
    cat test.txt
    echo "FAIL" && false
fi
echo "";echo "";

echo "Generating a new encryption key"
php bin/magento gene:encryption-key-manager:generate --force
echo "PASS"
echo "";echo "";

echo "Running reencrypt-unhandled-core-config-data"
php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data --force > test.txt || (cat test.txt && false)
cat test.txt
grep -q 'zzzzz/zzzzz/zzzz' test.txt
grep -q 'xyz123' test.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-unhandled-core-config-data - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running reencrypt-column"
php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token --force > test.txt || (cat test.txt && false)
cat test.txt
grep -q "$FAKE_RP_TOKEN" test.txt
grep -q abc123 test.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-column - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token --force | grep --context 999 'No old entries found'
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
touch var/log/system.log
ls -l var/log
if grep -q 'gene encryption manager' var/log/system.log; then
    cat var/log/system.log
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
touch var/log/system.log
ls -l var/log
if grep 'gene encryption manager' var/log/system.log | grep -q 'Magento\\'; then
    echo "PASS: A log was produced"
else
    cat var/log/system.log
    echo "FAIL: A log should be produced" && false
fi
echo "";echo "";

echo "Testing that gene_encryption_manager_only_log_old_decrypts=1 stops a log being written"
php bin/magento config:set --lock-env dev/debug/gene_encryption_manager_only_log_old_decrypts 1
php bin/magento cache:flush; php bin/magento | head -1; # clear and warm caches
rm -f var/log/*.log && php bin/magento | head -1 # trigger a decrypt of the stored system config
touch var/log/system.log
ls -l var/log
if grep -q 'gene encryption manager' var/log/system.log; then
    cat var/log/system.log
    echo "FAIL: No logs should be produced when the keys are up to date" && false
else
    echo "PASS: No logs were produced when the keys were up to date"
fi
echo "";echo "";

echo "Testing that gene_encryption_manager_only_log_old_decrypts=1 writes when an old key is used"
rm -f var/log/*.log && php bin/magento | head -1 # trigger a decrypt of the stored system config
vendor/bin/n98-magerun2 dev:decrypt '0:3:qwertyuiopasdfghjklzxcvbnm' # we are on a higher key than 0 now
touch var/log/system.log
ls -l var/log
if grep 'gene encryption manager' var/log/system.log | grep -q 'DecryptCommand'; then
    echo "PASS: We have a log hit when trying to decrypt with the old key"
else
    cat var/log/system.log
    echo "FAIL: We should have a log hit when trying to decrypt using an old key" && false
fi
echo "";echo "";

echo "A peek at the env.php"
grep -A10 "'crypt' =>" app/etc/env.php
echo "";echo "";
echo "DONE"
