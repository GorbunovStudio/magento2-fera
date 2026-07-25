<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ReviewSnapshot;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SnapshotRepositoryTest extends TestCase
{
    public function testSaveUsesSourceVersionConditionAndKeepsCreationDateImmutable(): void
    {
        /** @var AdapterInterface&MockObject $connection */
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('insertOnDuplicate')
            ->with(
                'fera_review_snapshots',
                self::arrayHasKey('fera_created_at'),
                self::callback(static function (array $updates): bool {
                    self::assertStringContainsString(
                        'VALUES(fera_updated_at) >= fera_updated_at',
                        (string) $updates['heading']
                    );
                    self::assertStringContainsString(
                        'fera_created_at IS NULL',
                        (string) $updates['fera_created_at']
                    );
                    return true;
                })
            )
            ->willReturn(1);

        /** @var ResourceConnection&MockObject $resourceConnection */
        $resourceConnection = $this->createMock(ResourceConnection::class);
        $resourceConnection->method('getConnection')->willReturn($connection);
        $resourceConnection->method('getTableName')->with('fera_review_snapshots')->willReturn('fera_review_snapshots');
        /** @var Json&MockObject $json */
        $json = $this->createMock(Json::class);
        $json->method('serialize')->willReturn('[]');

        $repository = new SnapshotRepository($resourceConnection, $json, new MediaNormalizer());

        self::assertSame(1, $repository->save([
            'review_id' => 'review-1',
            'heading' => '',
            'body' => '',
            'rating' => 5.0,
            'media' => [],
            'magento_store_id' => 7,
            'subject' => 'product',
            'external_product_id' => '42',
            'fera_product_id' => 'fpro-1',
            'product_name' => 'Product',
            'state' => 'approved',
            'is_test' => false,
            'fera_created_at' => '2026-07-13 00:00:00',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ]));
    }
}
