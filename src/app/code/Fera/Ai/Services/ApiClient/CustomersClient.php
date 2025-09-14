<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ApiClient;

use Fera\Ai\Api\ApiClient\CustomersClientInterface;
use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Services\ApiClient;

/**
 * @phpstan-import-type FeraCustomerData from CustomersClientInterface
 */
class CustomersClient implements CustomersClientInterface
{
    protected const BASE_ENDPOINT = 'v3/private/customers';

    public function __construct(
        private ApiClient $apiClient
    ) {
    }

    public function get(string $customerId, int $storeId): array
    {
        $endpoint = static::BASE_ENDPOINT . '/' . $customerId;
        $decoded = $this->apiClient->get($endpoint, $storeId);

        if (!isset($decoded['name']) || !is_string($decoded['name'])
            || !isset($decoded['email']) || !is_string($decoded['email'])
        ) {
            throw new FeraApiException(sprintf(
                'Invalid customer data received from Fera API for customer %s',
                $customerId
            ));
        }

        $result = [
            'name' => $decoded['name'],
            'email' => $decoded['email'],
        ];

        if (isset($decoded['phone_number']) && is_string($decoded['phone_number'])) {
            $result['phone_number'] = $decoded['phone_number'];
        }
        
        if (isset($decoded['external_id']) && $decoded['external_id'] !== null) {
            $result['external_id'] = (int) $decoded['external_id'];
        }
        
        return $result;
    }

    public function update(string $customerId, array $customerData, int $storeId): void
    {
        $endpoint = static::BASE_ENDPOINT . '/' . $customerId;
        $this->apiClient->put($endpoint, $customerData, $storeId);
    }
}
