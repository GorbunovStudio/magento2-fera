<?php

declare(strict_types=1);

/**
 * @author: Sviatoslav Lashkiv
 * @email: ss.lashkiv@gmail.com
 * @team: MageCloud
 */

namespace Fera\Ai\Logger;

use Monolog\Handler\HandlerInterface;

class Logger extends \Monolog\Logger
{
    /**
     * Logger constructor.
     * @param string $name
     * @param HandlerInterface[] $handlers Optional stack of handlers, the first one in the array is called first, etc
     * @param callable[] $processors Optional array of processors
     */
    public function __construct(string $name, $handlers = [], $processors = [])
    {
        parent::__construct($name, $handlers, $processors);
    }
}
