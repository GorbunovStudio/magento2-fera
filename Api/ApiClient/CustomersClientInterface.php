<?php

declare(strict_types=1);

namespace Fera\Ai\Api\ApiClient;

/**
 * @phpstan-type FeraCustomerData array{
 *     external_id?: int,
 *     name: string,
 *     email: string,
 *     phone_number?: string|null,
 * }
 *
 * @phpstan-type FeraPersistedCustomerData array{
 *     id: string,
 *     external_id?: int,
 *     name: string,
 *     email: string,
 *     phone_number?: string|null,
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
     * @phpstan-return FeraPersistedCustomerData
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

    /**
     * Find customer by email address
     *
     * @param string $email
     * @param int $storeId
     * @return array|null
     * @phpstan-return FeraPersistedCustomerData|null
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function findByEmail(string $email, int $storeId): ?array;

    /**
     * Create a new customer in Fera
     *
     * @param array $customerData
     * @phpstan-param FeraCustomerData $customerData
     * @param int $storeId
     * @return string Fera customer ID
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function create(array $customerData, int $storeId): string;
}
