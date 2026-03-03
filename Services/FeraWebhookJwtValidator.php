<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

class FeraWebhookJwtValidator
{
    public function __construct(private FeraHelper $helper)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function validateToken(string $jwt, int $storeId, string $eventName): array
    {
        $secret = $this->helper->getSecretKey($storeId);
        if (!is_string($secret) || $secret === '') {
            throw new RuntimeException('Forbidden');
        }

        try {
            $decoded = JWT::decode($jwt, new Key($secret, 'HS256'));
        } catch (Throwable $exception) {
            throw new RuntimeException('Forbidden', 0, $exception);
        }

        $claims = get_object_vars($decoded);

        $claimEventName = $claims['webhook_event_name'] ?? null;
        if (!is_string($claimEventName) || $claimEventName !== $eventName) {
            throw new RuntimeException('Forbidden');
        }

        $storeClaim = $claims['store_id'] ?? null;
        if (!$this->hasUsableStoreId($storeClaim)) {
            throw new RuntimeException('Forbidden');
        }

        return $claims;
    }

    private function hasUsableStoreId(mixed $storeClaim): bool
    {
        if (is_string($storeClaim)) {
            return $storeClaim !== '';
        }

        return is_int($storeClaim) || is_float($storeClaim);
    }
}
