<?php
use Magento\Framework\App\Bootstrap;
require_once '/var/www/html/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, $_SERVER);
$obj = $bootstrap->getObjectManager();

/** @var \Magento\Framework\App\ResourceConnection $connection */
$connection = $obj->get(\Magento\Framework\App\ResourceConnection::class);
$connection->getConnection()->query('SET FOREIGN_KEY_CHECKS = 0;');
$connection->getConnection()->query('delete from sales_order_payment where parent_id=1;');

/** @var \Magento\Framework\Encryption\EncryptorInterface $encryptor */
$encryptor = $obj->get(\Magento\Framework\Encryption\EncryptorInterface::class);
$ccNumberEnc = $encryptor->encrypt('cc_number_enc_abc123');

$rowData = "(1, '$ccNumberEnc'),";

$insertQueryNull = trim('INSERT INTO sales_order_payment (parent_id, cc_number_enc) VALUES ' . str_repeat($rowData, 10000), ", ");
for ($i = 0; $i < 100; $i++) {
    $connection->getConnection()->query($insertQueryNull);
}
$connection->getConnection()->query('SET FOREIGN_KEY_CHECKS = 1;');

// Get the total count of records
$countSelect = $connection->getConnection()->select()
    ->from('sales_order_payment', ['COUNT(*) AS total_count']);
$totalCount = $connection->getConnection()->fetchOne($countSelect);

echo "There are $totalCount items in sales_order_payment" . PHP_EOL;
echo "DONE stub_sales_order_payment.php". PHP_EOL;
