<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ReviewSnapshot;

use Fera\Ai\Services\ReviewSnapshot\SnapshotComparator;
use PHPUnit\Framework\TestCase;

class SnapshotComparatorTest extends TestCase
{
    public function testIgnoresMediaUrlChangesWhenMediaIdMatches(): void
    {
        $previous = $this->snapshot([
            ['id' => 'media-1', 'url' => 'https://cdn.example/review.jpg?version=1'],
        ]);
        $current = $this->snapshot([
            ['id' => 'media-1', 'url' => 'https://cdn.example/review.jpg?version=2'],
        ]);

        self::assertSame([], (new SnapshotComparator())->compare($previous, $current));
    }

    public function testDetectsMediaWithANewId(): void
    {
        $previous = $this->snapshot([
            ['id' => 'media-1', 'url' => 'https://cdn.example/review.jpg'],
        ]);
        $current = $this->snapshot([
            ['id' => 'media-2', 'url' => 'https://cdn.example/review.jpg'],
        ]);

        self::assertArrayHasKey('media', (new SnapshotComparator())->compare($previous, $current));
    }

    public function testIgnoresExternalOrderIdChanges(): void
    {
        $previous = $this->snapshot([], 'order-1');
        $current = $this->snapshot([], 'order-2');

        self::assertSame([], (new SnapshotComparator())->compare($previous, $current));
    }

    /**
     * @param list<array{id: string, url: string}> $media
     * @return array{
     *     review_id: string,
     *     heading: string,
     *     body: string,
     *     rating: float,
     *     media: list<array{id: string, url: string}>,
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
    private function snapshot(array $media, string $externalOrderId = 'order-1'): array
    {
        return [
            'review_id' => 'review-1',
            'rating' => 5.0,
            'heading' => 'Incredible!',
            'body' => 'Same review',
            'media' => $media,
            'magento_store_id' => 7,
            'subject' => 'product',
            'external_order_id' => $externalOrderId,
            'external_product_id' => 'product-1',
            'fera_product_id' => 'fera-product-1',
            'product_name' => 'Product',
            'state' => 'approved',
            'is_test' => false,
            'fera_created_at' => '2026-07-25 00:00:00',
            'fera_updated_at' => '2026-07-25 00:00:00',
        ];
    }
}
