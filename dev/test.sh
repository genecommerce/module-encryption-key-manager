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
vendor/bin/n98-magerun2 config:store:set zzzzz/zzzzz/zzzz abc123 --encrypt
FAKE_RP_TOKEN=$(vendor/bin/n98-magerun2 dev:encrypt 'abc123')
vendor/bin/n98-magerun2 db:query "update admin_user set rp_token='$FAKE_RP_TOKEN' where username='$ADMIN'"
echo "Generated FAKE_RP_TOKEN=$FAKE_RP_TOKEN and assigned to $ADMIN"

echo "";echo "";

echo "Verifying commands need to use --force"
if ! php bin/magento gene:encryption-key-manager:generate | grep -q 'Run with --force'; then
    echo "PASS: generate needs to run with force"
fi
if ! php bin/magento gene:encryption-key-manager:invalidate | grep -q 'Run with --force'; then
    echo "PASS: invalidate needs to run with force"
fi
if ! php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data | grep -q 'Run with --force'; then
    echo "PASS: reencrypt-unhandled-core-config-data needs to run with force"
fi
if ! php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token --force | grep -q 'Run with --force'; then
    echo "PASS: reencrypt-column needs to run with force"
fi
echo "";echo "";

echo "Verifying you cannot invalidate with only 1 key"
if ! php bin/magento gene:encryption-key-manager:invalidate --force | grep -q 'Cannot invalidate when there is only one key'; then
    echo "PASS: You cannot invalidate with only 1 key"
fi
echo "";echo "";

echo "Generating a new encryption key"
php bin/magento gene:encryption-key-manager:generate --force
echo "PASS"
echo "";echo "";

echo "Running reencrypt-unhandled-core-config-data"
php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data --force > unhandled.txt
cat unhandled.txt
grep -q 'zzzzz/zzzzz/zzzz' unhandled.txt
grep -q 'abc123' unhandled.txt
echo "PASS"
echo "";echo "";
echo "Running reencrypt-unhandled-core-config-data - again to verify it was all processed"
php bin/magento gene:encryption-key-manager:reencrypt-unhandled-core-config-data --force | grep --context 999 'No old entries found'
echo "PASS"
echo "";echo "";

echo "Running reencrypt-column"
php bin/magento gene:encryption-key-manager:reencrypt-column admin_user user_id rp_token --force > column.txt
cat column.txt
grep -q "$FAKE_RP_TOKEN" column.txt
grep -q abc123 column.txt
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

echo "A peek at the env.php"
grep -A10 "'crypt' =>" app/etc/env.php
echo "DONE"
