<?php

declare(strict_types=1);

namespace Fera\Ai\Api\ApiClient;

/**
 * @phpstan-type Variant array{
 *     id: int|string,
 *     name: string,
 *     status?: string,
 *     created_at: string,
 *     modified_at: string,
 *     stock?: float,
 *     in_stock?: bool,
 *     price?: float,
 *     platform_data: array{sku: string},
 *     thumbnail_url?: string
 * }
 *
 * @phpstan-type ProductData array{
 *     id: int|string,
 *     external_id: int|string,
 *     sku?: string,
 *     brand?: string,
 *     name: string,
 *     price?: float,
 *     status?: string,
 *     created_at: string,
 *     modified_at: string,
 *     stock?: float,
 *     in_stock?: bool,
 *     url: string,
 *     thumbnail_url: string,
 *     needs_shipping: bool,
 *     hidden: bool,
 *     tags: string[],
 *     variants: Variant[],
 *     platform_data: array{sku: string, type: string|mixed[], regular_price?: float}
 * }
 *
 * @phpstan-type ProductListItem array{
 *     id: string,
 *     external_id: string
 * }
 *
 * @phpstan-type ProductsListResponse array{
 *     data: array<int, ProductListItem>,
 *     meta?: array{page?: int, page_count?: int}
 * }
 */
interface ProductsClientInterface
{
    /**
     * Create a new product in Fera
     *
     * @param array $product
     * @phpstan-param ProductData $product
     * @param int|null $storeId
     * @return string Fera product ID
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function create(array $product, ?int $storeId = null): string;

    /**
     * Update an existing product in Fera
     *
     * @param string $feraId
     * @param array $product
     * @phpstan-param ProductData $product
     * @param int|null $storeId
     * @throws \Fera\Ai\Exception\FeraApiException
     * @throws \Fera\Ai\Exception\ProductNotFoundException
     */
    public function update(string $feraId, array $product, ?int $storeId = null): void;

    /**
     * List products from Fera with pagination
     *
     * @param int $page
     * @param int $pageSize
     * @param int|null $storeId
     * @return array
     * @phpstan-return ProductsListResponse
     * @throws \Fera\Ai\Exception\FeraApiException
     */
    public function list(int $page, int $pageSize, ?int $storeId = null): array;
}
