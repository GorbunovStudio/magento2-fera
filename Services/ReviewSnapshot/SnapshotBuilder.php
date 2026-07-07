<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;

/**
 * @phpstan-type ReviewMedia list<array{id: string, url: string}>
 * @phpstan-type ReviewSnapshot array{review_id: string, heading: string, body: string, rating: float, media: ReviewMedia}
 */
class SnapshotBuilder
{
    public function __construct(private MediaNormalizer $mediaNormalizer)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @return ReviewSnapshot
     */
    public function build(array $payload): array
    {
        return [
            'review_id' => $this->resolveReviewId($payload),
            'heading' => $this->extractString($payload, 'heading'),
            'body' => $this->extractString($payload, 'body'),
            'rating' => $this->resolveRating($payload),
            'media' => $this->mediaNormalizer->normalize($payload['media'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveReviewId(array $payload): string
    {
        $reviewId = $payload['id'] ?? null;
        if (!is_string($reviewId) || trim($reviewId) === '') {
            throw new WebapiException(new Phrase('Field "id" must be a non-empty string'), 0, WebapiException::HTTP_BAD_REQUEST);
        }

        return trim($reviewId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRating(array $payload): float
    {
        $rating = $payload['rating'] ?? null;
        if (!is_numeric($rating)) {
            throw new WebapiException(new Phrase('Field "rating" must be numeric'), 0, WebapiException::HTTP_BAD_REQUEST);
        }

        return (float) $rating;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }
}
