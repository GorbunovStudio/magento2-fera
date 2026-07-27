<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Integration\Services\ReviewSnapshot;

use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * @magentoDbIsolation enabled
 */
class SnapshotRepositoryTest extends TestCase
{
    private SnapshotRepository $snapshotRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotRepository = Bootstrap::getObjectManager()->get(SnapshotRepository::class);
    }

    public function testStaleSnapshotDoesNotReplaceNewerValuesButFillsMissingCreationTime(): void
    {
        $this->snapshotRepository->save($this->snapshot([
            'heading' => 'Current heading',
            'fera_created_at' => null,
            'fera_updated_at' => '2026-07-14 00:00:00',
        ]));
        $this->snapshotRepository->save($this->snapshot([
            'heading' => 'Stale heading',
            'fera_created_at' => '2026-07-13 00:00:00',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ]));

        self::assertSame(
            [
                'heading' => 'Current heading',
                'fera_created_at' => '2026-07-13 00:00:00',
                'fera_updated_at' => '2026-07-14 00:00:00',
            ],
            array_intersect_key(
                $this->snapshotRepository->getByReviewId('integration-review-snapshot-1') ?? [],
                array_flip(['heading', 'fera_created_at', 'fera_updated_at'])
            )
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return array_replace([
            'review_id' => 'integration-review-snapshot-1',
            'heading' => '',
            'body' => '',
            'rating' => 5.0,
            'media' => [],
            'magento_store_id' => 1,
            'subject' => 'product',
            'external_product_id' => '42',
            'fera_product_id' => 'fpro-1',
            'product_name' => 'Product',
            'state' => 'approved',
            'is_test' => false,
            'fera_created_at' => '2026-07-13 00:00:00',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ], $overrides);
    }
}
