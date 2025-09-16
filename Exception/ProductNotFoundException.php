<?php

declare(strict_types=1);

namespace Fera\Ai\Exception;

use Throwable;

class ProductNotFoundException extends FeraApiException
{
    public function __construct(
        private string $feraId,
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        if ($message === '') {
            $message = sprintf('Product not found with Fera ID: %s', $feraId);
        }
        
        parent::__construct($message, $code, $previous);
    }

    public function getFeraId(): string
    {
        return $this->feraId;
    }
}
