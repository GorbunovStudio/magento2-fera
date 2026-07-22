<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ApiClient;

use Fera\Ai\Api\ApiClient\ReviewsClientInterface;
use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Services\ApiClient;

/**
 * @phpstan-import-type FeraReviewData from ReviewsClientInterface
 * @phpstan-import-type ReviewsListResponse from ReviewsClientInterface
 */
class ReviewsClient implements ReviewsClientInterface
{
    protected const BASE_ENDPOINT = 'v3/private/reviews';

    public function __construct(
        private ApiClient $apiClient
    ) {
    }

    public function create(array $review, int $storeId): string
    {
        $payload = ['data' => $review];
        $response = $this->apiClient->post(static::BASE_ENDPOINT, $payload, $storeId);

        $reviewId = $response['id'] ?? null;
        if (!is_string($reviewId) || $reviewId === '') {
            throw new FeraApiException(sprintf(
                'Fera API create review response missing id: %s',
                json_encode($response)
            ));
        }

        return $reviewId;
    }

    public function list(int $page, int $pageSize, int $storeId): array
    {
        $decoded = $this->apiClient->get(static::BASE_ENDPOINT, $storeId, [
            'page' => $page,
            'page_size' => $pageSize,
            'subject' => 'both',
        ]);

        /** @var ReviewsListResponse $result */
        return [
            'data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : [],
            'meta' => isset($decoded['meta']) && is_array($decoded['meta']) ? $decoded['meta'] : [],
        ];
    }
}
