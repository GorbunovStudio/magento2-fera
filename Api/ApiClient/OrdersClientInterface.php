<?php

declare(strict_types=1);

namespace Fera\Ai\Api\ApiClient;

/**
 * @phpstan-type FeraLineItem array{
 *     product_id: int,
 *     price?: float,
 *     total?: float,
 *     name: string,
 *     quantity: int,
 *     variant_id?: int
 * }
 *
 * @phpstan-import-type FeraCustomerData from CustomersClientInterface
 *
 * @phpstan-type FeraAddressData array{
 *     name: string,
 *     address1: string,
 *     address2: string,
 *     city_name: string,
 *     region_name: string,
 *     zip_code: string
 * }
 *
 * @phpstan-type FeraOrder array{
 *     total?: float,
 *     total_usd?: float,
 *     external_updated_at: string,
 *     line_items: array<int, FeraLineItem>,
 *     external_id: string,
 *     number: string,
 *     external_created_at: string,
 *     customer?: FeraCustomerData,
 *     customer_id?: string,
 *     tags?: array<string>,
 *     source_name?: string,
 *     shipping_address?: FeraAddressData,
 *     billing_address?: FeraAddressData,
 *     phone_number?: string|null,
 *     is_cancelled?: bool
 * }
 *
 * @phpstan-type FeraOrderUpdate array{
 *     total?: float,
 *     total_usd?: float,
 *     external_updated_at?: string,
 *     line_items?: array<int, FeraLineItem>,
 *     external_id?: string,
 *     number?: string,
 *     external_created_at?: string,
 *     customer?: FeraCustomerData,
 *     customer_id?: string,
 *     tags?: array<string>,
 *     source_name?: string,
 *     shipping_address?: FeraAddressData,
 *     billing_address?: FeraAddressData,
 *     phone_number?: string|null,
 *     is_cancelled?: bool
 * }
 *
 * @phpstan-type FeraFulfillmentData array{
 *     fulfilled_at: string,
 *     external_id: int
 * }
 */
interface OrdersClientInterface
{
    /**
     * Create a new order in Fera
     *
     * @param array $order
     * @phpstan-param FeraOrder $order
     * @param int|null $storeId
     * @return string Fera order ID
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function create(array $order, ?int $storeId = null): string;

    /**
     * Update an existing order in Fera
     *
     * @param string $feraId
     * @param array $order
     * @phpstan-param FeraOrderUpdate $order
     * @param int $storeId
     * @return array
     * @phpstan-return FeraOrder
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function update(string $feraId, array $order, int $storeId): array;

    /**
     * Mark an order as fulfilled in Fera
     *
     * @param string $feraId
     * @param array $fulfillmentData
     * @phpstan-param FeraFulfillmentData $fulfillmentData
     * @param int $storeId
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function fulfill(string $feraId, array $fulfillmentData, int $storeId): void;

    /**
     * Get an order from Fera (optional, for completeness)
     *
     * @param string $feraId
     * @param int $storeId
     * @return array
     * @phpstan-return FeraOrder
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function get(string $feraId, int $storeId): array;
}
