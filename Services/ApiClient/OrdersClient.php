<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ApiClient;

use Fera\Ai\Api\ApiClient\OrdersClientInterface;
use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Services\ApiClient;

/**
 * @phpstan-import-type FeraOrder from OrdersClientInterface
 * @phpstan-import-type FeraFulfillmentData from OrdersClientInterface
 */
class OrdersClient implements OrdersClientInterface
{
    protected const BASE_ENDPOINT = 'v3/private/orders';

    public function __construct(
        private ApiClient $apiClient
    ) {
    }

    public function create(array $order, ?int $storeId = null): string
    {
        $response = $this->apiClient->post(static::BASE_ENDPOINT, $order, $storeId);

        $feraId = $response['id'] ?? null;
        if (!is_string($feraId) || $feraId === '') {
            throw new FeraApiException(sprintf(
                'Fera API create response missing id for order %s: %s',
                $order['external_id'],
                json_encode($response)
            ));
        }

        return $feraId;
    }

    public function update(string $feraId, array $order, int $storeId): array
    {
        $endpoint = static::BASE_ENDPOINT . '/' . $feraId;
        
        /** @var FeraOrder $response */
        $response = $this->apiClient->put($endpoint, $order, $storeId);
        
        return $response;
    }

    public function fulfill(string $feraId, array $fulfillmentData, int $storeId): void
    {
        $endpoint = static::BASE_ENDPOINT . '/' . $feraId . '/fulfill';
        
        $this->apiClient->put($endpoint, $fulfillmentData, $storeId);
    }

    public function get(string $feraId, int $storeId): array
    {
        $endpoint = static::BASE_ENDPOINT . '/' . $feraId;
        
        /** @var FeraOrder $response */
        $response = $this->apiClient->get($endpoint, $storeId);
        
        return $response;
    }
}
