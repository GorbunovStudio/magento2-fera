<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * @phpstan-type ReviewMedia list<array{id: string, url: string}>
 * @phpstan-type ReviewSnapshot array{
 *     review_id: string,
 *     heading: string,
 *     body: string,
 *     rating: float,
 *     media: ReviewMedia,
 *     magento_store_id: int|null,
 *     subject: string|null,
 *     external_order_id: string|null,
 *     external_product_id: string|null,
 *     fera_product_id: string|null,
 *     product_name: string|null,
 *     state: string|null,
 *     is_test: bool|null,
 *     fera_created_at: string|null,
 *     fera_updated_at: string|null
 * }
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
    public function build(array $payload, ?int $magentoStoreId = null): array
    {
        $product = isset($payload['product']) && is_array($payload['product'])
            ? $payload['product']
            : [];
        $subject = $this->extractNullableString($payload, 'subject');
        $isProductReview = $subject !== 'store';

        return [
            'review_id' => $this->resolveReviewId($payload),
            'heading' => $this->extractString($payload, 'heading'),
            'body' => $this->extractString($payload, 'body'),
            'rating' => $this->resolveRating($payload),
            'media' => $this->mediaNormalizer->normalize($payload['media'] ?? []),
            'magento_store_id' => $magentoStoreId,
            'subject' => $subject,
            'external_order_id' => $this->extractNullableString($payload, 'external_order_id'),
            'external_product_id' => $isProductReview
                ? $this->resolveExternalProductId($payload, $product)
                : null,
            'fera_product_id' => $isProductReview ? $this->extractNullableString($product, 'id') : null,
            'product_name' => $isProductReview ? $this->extractNullableString($product, 'name') : null,
            'state' => $this->extractNullableString($payload, 'state'),
            'is_test' => $this->resolveNullableBool($payload['is_test'] ?? null),
            'fera_created_at' => $this->normalizeTimestamp($payload, 'created_at'),
            'fera_updated_at' => $this->normalizeTimestamp($payload, 'updated_at'),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveReviewId(array $payload): string
    {
        $reviewId = $payload['id'] ?? null;
        if (!is_string($reviewId) || trim($reviewId) === '') {
            throw new InvalidArgumentException('Field "id" must be a non-empty string');
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
            throw new InvalidArgumentException('Field "rating" must be numeric');
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

    /**
     * @param array<string, mixed> $payload
     */
    private function extractNullableString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if (is_string($value)) {
            $value = trim($value);
            return $value !== '' ? $value : null;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $product
     */
    private function resolveExternalProductId(array $payload, array $product): ?string
    {
        return $this->extractNullableString($payload, 'external_product_id')
            ?? $this->extractNullableString($product, 'external_id');
    }

    private function resolveNullableBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 'true' => true,
                '0', 'false' => false,
                default => null,
            };
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalizeTimestamp(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an ISO-8601 timestamp', $key));
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            throw new InvalidArgumentException(sprintf('Field "%s" must be an ISO-8601 timestamp', $key));
        }
    }
}
