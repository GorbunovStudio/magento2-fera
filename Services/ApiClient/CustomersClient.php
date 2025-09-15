<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ApiClient;

use Fera\Ai\Api\ApiClient\CustomersClientInterface;
use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Services\ApiClient;

/**
 * @phpstan-import-type FeraCustomerData from CustomersClientInterface
 * @phpstan-import-type FeraPersistedCustomerData from CustomersClientInterface
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

        if (!isset($decoded['id']) || !is_string($decoded['id'])
            || !isset($decoded['name']) || !is_string($decoded['name'])
            || !isset($decoded['email']) || !is_string($decoded['email'])
        ) {
            throw new FeraApiException(sprintf(
                'Invalid customer data received from Fera API for customer %s',
                $customerId
            ));
        }

        $result = [
            'id' => $decoded['id'],
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

    public function findByEmail(string $email, int $storeId): ?array
    {
        $response = $this->apiClient->get(static::BASE_ENDPOINT, $storeId, ['email' => $email]);

        if (empty($response) || !is_array($response)
            || !isset($response['data']) || !is_array($response['data'])
        ) {
            throw new FeraApiException(sprintf(
                'Invalid response received Fera API: %s',
                json_encode($response)
            ));
        }

        // API returns array of customers, take the first match
        $customer = $response['data'][0] ?? null;
        if (!$customer || !is_array($customer)) {
            return null;
        }

        if (!isset($customer['id'], $customer['name'], $customer['email'])
            || !is_string($customer['id']) || !is_string($customer['name']) || !is_string($customer['email'])
        ) {
            throw new FeraApiException(sprintf(
                'Invalid customer data received from Fera API search for email %s: %s',
                $email,
                json_encode($customer)
            ));
        }

        $result = [
            'id' => $customer['id'],
            'name' => $customer['name'],
            'email' => $customer['email'],
        ];

        if (isset($customer['phone_number']) && is_string($customer['phone_number'])) {
            $result['phone_number'] = $customer['phone_number'];
        }
        
        if (isset($customer['external_id']) && $customer['external_id'] !== null) {
            $result['external_id'] = (int) $customer['external_id'];
        }
        
        return $result;
    }

    public function create(array $customerData, int $storeId): string
    {
        $response = $this->apiClient->post(static::BASE_ENDPOINT, $customerData, $storeId);

        $customerId = $response['id'] ?? null;
        if (!is_string($customerId) || $customerId === '') {
            throw new FeraApiException(sprintf(
                'Fera API create customer response missing id: %s',
                json_encode($response)
            ));
        }

        return $customerId;
    }
}
