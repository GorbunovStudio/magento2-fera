<?php

declare(strict_types=1);

namespace Fera\Ai\Exception;

use Throwable;

class HttpRequestException extends FeraApiException
{
    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param int|null $statusCode
     * @param string|null $responseBody
     * @param array<string, mixed>|null $responseData
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        private ?int $statusCode = null,
        private ?string $responseBody = null,
        private ?array $responseData = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResponseData(): ?array
    {
        return $this->responseData;
    }
}
