<?php

declare(strict_types=1);

namespace Fera\Ai\Api\ApiClient;

/**
 * @phpstan-type FeraCustomerData array{
 *     external_id?: int,
 *     name: string,
 *     email: string,
 *     phone_number?: string|null
 * }
 */
interface CustomersClientInterface
{
    /**
     * Get customer data from Fera
     *
     * @param string $customerId
     * @param int $storeId
     * @return array
     * @phpstan-return FeraCustomerData
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function get(string $customerId, int $storeId): array;

    /**
     * Update customer data in Fera
     *
     * @param string $customerId
     * @param array $customerData
     * @phpstan-param FeraCustomerData $customerData
     * @param int $storeId
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function update(string $customerId, array $customerData, int $storeId): void;
}
