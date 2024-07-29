<?php
declare(strict_types=1);
namespace Gene\EncryptionKeyManager\Logger;

use Magento\Framework\Logger\Handler\Base as BaseHandler;
use Monolog\Logger as MonologLogger;

class CustomHandler extends BaseHandler
{
    /**
     * Logging level
     *
     * @var int
     */
    protected $loggerType = MonologLogger::INFO;

    /**
     * File name
     *
     * @var string
     */
    protected $fileName = '/var/log/encryption_key_manager.log';
}
