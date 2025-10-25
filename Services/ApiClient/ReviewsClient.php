<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ApiClient;

use Fera\Ai\Api\ApiClient\ReviewsClientInterface;
use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Services\ApiClient;

/**
 * @phpstan-import-type FeraReviewData from ReviewsClientInterface
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
}
