<?php

declare(strict_types=1);

namespace Fera\Ai\Api\ApiClient;

/**
 * @phpstan-type FeraStoreReply array{body: string, created_at?: string}
 *
 * @phpstan-type FeraReviewData array{
 *     body: string,
 *     rating: int,
 *     state: 'pending_approval'|'pending_update'|'approved'|'declined_approval',
 *     created_at: string,
 *     is_verified: bool,
 *     product_id: string|int,
 *     external_order_id: string,
 *     external_customer_id: string,
 *     heading?: string,
 *     media?: array<int, string>,
 *     store_reply?: FeraStoreReply
 * }
 */
interface ReviewsClientInterface
{
    /**
     * Create a new review in Fera
     *
     * @param array $review
     * @phpstan-param FeraReviewData $review
     * @param int $storeId
     * @return string Fera review ID
     * @throws \Fera\Ai\Exception\FeraApiException
     * @throws \Fera\Ai\Exception\HttpRequestException
     */
    public function create(array $review, int $storeId): string;
}
