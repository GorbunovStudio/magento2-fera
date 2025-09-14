<?php

declare(strict_types=1);

/**
 * @author: Sviatoslav Lashkiv
 * @email: ss.lashkiv@gmail.com
 * @team: MageCloud
 */

namespace Fera\Ai\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * Logging level
     * @var int
     */
    protected $loggerType = Logger::INFO;

    /**
     * File name
     * @var string
     */
    protected $fileName = '/var/log/fera_ai.log';
}
