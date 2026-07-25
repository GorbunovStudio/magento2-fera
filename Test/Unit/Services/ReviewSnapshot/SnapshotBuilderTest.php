<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ReviewSnapshot;

use Fera\Ai\Services\ReviewSnapshot\MediaNormalizer;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SnapshotBuilderTest extends TestCase
{
    public function testBuildsProductReportingDimensionsAndNormalizesUtcTimestamps(): void
    {
        $builder = new SnapshotBuilder(new MediaNormalizer());
        $payload = $this->fixture('review_created_product.json');

        $snapshot = $builder->build($payload, 7);

        self::assertSame('frev_product_001', $snapshot['review_id']);
        self::assertSame(7, $snapshot['magento_store_id']);
        self::assertSame('product', $snapshot['subject']);
        self::assertSame('fpro_001', $snapshot['fera_product_id']);
        self::assertSame('magento-42', $snapshot['external_product_id']);
        self::assertSame('Sanitized Product', $snapshot['product_name']);
        self::assertFalse($snapshot['is_test']);
        self::assertSame('2026-07-13 18:00:00', $snapshot['fera_created_at']);
        self::assertSame('2026-07-14 08:30:00', $snapshot['fera_updated_at']);
    }

    public function testBuildsStoreReviewWithNullProductContext(): void
    {
        $builder = new SnapshotBuilder(new MediaNormalizer());

        $snapshot = $builder->build($this->fixture('review_created_store.json'), 7);

        self::assertSame('store', $snapshot['subject']);
        self::assertNull($snapshot['fera_product_id']);
        self::assertNull($snapshot['external_product_id']);
        self::assertNull($snapshot['product_name']);
        self::assertTrue($snapshot['is_test']);
    }

    public function testRejectsMalformedSourceTimestamp(): void
    {
        $builder = new SnapshotBuilder(new MediaNormalizer());
        $payload = $this->fixture('review_created_product.json');
        $payload['created_at'] = 'not-a-timestamp';

        $this->expectException(InvalidArgumentException::class);
        $builder->build($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $contents = file_get_contents(__DIR__ . '/../../../Fixture/' . $name);
        self::assertIsString($contents);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
