<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ReviewSnapshot;

use Fera\Ai\Api\Data\ReviewSnapshotInterface;
use Fera\Ai\Model\ReviewSnapshot;
use Fera\Ai\Model\ReviewSnapshotFactory;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot as ReviewSnapshotResource;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot\Collection;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot\CollectionFactory;
use Fera\Ai\Services\ReviewSnapshot\MediaNormalizer;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SnapshotRepositoryTest extends TestCase
{
    public function testSaveCreatesNewEntityThroughFactory(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $existing = $this->createMock(ReviewSnapshot::class);
        $existing->method('getId')->willReturn(null);
        $collection->method('getFirstItem')->willReturn($existing);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $newSnapshot = $this->createMock(ReviewSnapshot::class);
        foreach ([
            'setReviewId',
            'setHeading',
            'setBody',
            'setRating',
            'setMedia',
            'setMagentoStoreId',
            'setSubject',
            'setExternalProductId',
            'setFeraProductId',
            'setProductName',
            'setState',
            'setIsTest',
            'setFeraCreatedAt',
            'setFeraUpdatedAt',
        ] as $method) {
            $newSnapshot->method($method)->willReturnSelf();
        }

        /** @var ReviewSnapshotFactory&MockObject $snapshotFactory */
        $snapshotFactory = $this->createMock(ReviewSnapshotFactory::class);
        $snapshotFactory->expects(self::once())->method('create')->willReturn($newSnapshot);

        /** @var ReviewSnapshotResource&MockObject $resource */
        $resource = $this->createMock(ReviewSnapshotResource::class);
        $resource->expects(self::once())->method('save')->with($newSnapshot);

        /** @var Json&MockObject $json */
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('serialize')->with([])->willReturn('[]');

        $this->repository($resource, $snapshotFactory, $collectionFactory, $json)->save($this->snapshot());
    }

    public function testStaleSnapshotDoesNotOverwriteExistingEntity(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $existing = $this->createMock(ReviewSnapshot::class);
        $existing->method('getId')->willReturn(1);
        $existing->method('getFeraUpdatedAt')->willReturn('2026-07-14 00:00:00');
        $existing->method('getFeraCreatedAt')->willReturn('2026-07-13 00:00:00');
        $collection->method('getFirstItem')->willReturn($existing);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        /** @var ReviewSnapshotResource&MockObject $resource */
        $resource = $this->createMock(ReviewSnapshotResource::class);
        $resource->expects(self::never())->method('save');

        $this->repository(
            $resource,
            $this->createMock(ReviewSnapshotFactory::class),
            $collectionFactory,
            $this->createMock(Json::class)
        )->save($this->snapshot(['fera_updated_at' => '2026-07-12 00:00:00']));
    }

    public function testEqualSourceVersionUpdatesChangedMutableField(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $existing = $this->existingSnapshotModel();
        $existing->method('getHeading')->willReturn('Old heading');
        $existing->expects(self::once())
            ->method('setData')
            ->with(ReviewSnapshotInterface::HEADING, 'New heading')
            ->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($existing);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        /** @var ReviewSnapshotResource&MockObject $resource */
        $resource = $this->createMock(ReviewSnapshotResource::class);
        $resource->expects(self::once())->method('save')->with($existing);
        /** @var Json&MockObject $json */
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('serialize')->with([])->willReturn('[]');

        $this->repository(
            $resource,
            $this->createMock(ReviewSnapshotFactory::class),
            $collectionFactory,
            $json
        )->save($this->snapshot(['heading' => 'New heading']));
    }

    public function testStaleSnapshotCanFillMissingCreationTimestamp(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $existing = $this->createMock(ReviewSnapshot::class);
        $existing->method('getId')->willReturn(1);
        $existing->method('getFeraUpdatedAt')->willReturn('2026-07-14 00:00:00');
        $existing->method('getFeraCreatedAt')->willReturn(null);
        $existing->expects(self::once())
            ->method('setData')
            ->with(ReviewSnapshotInterface::FERA_CREATED_AT, '2026-07-13 00:00:00')
            ->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($existing);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        /** @var ReviewSnapshotResource&MockObject $resource */
        $resource = $this->createMock(ReviewSnapshotResource::class);
        $resource->expects(self::once())->method('save')->with($existing);

        $this->repository(
            $resource,
            $this->createMock(ReviewSnapshotFactory::class),
            $collectionFactory,
            $this->createMock(Json::class)
        )->save($this->snapshot(['fera_updated_at' => '2026-07-12 00:00:00']));
    }

    public function testGetByReviewIdMapsEntityToExistingSnapshotContract(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())
            ->method('addFieldToFilter')
            ->with(ReviewSnapshotInterface::REVIEW_ID, 'review-1')
            ->willReturnSelf();
        $collection->expects(self::once())->method('setPageSize')->with(1)->willReturnSelf();

        $model = $this->createMock(ReviewSnapshot::class);
        $model->method('getId')->willReturn(1);
        $model->method('getReviewId')->willReturn('review-1');
        $model->method('getHeading')->willReturn('Heading');
        $model->method('getBody')->willReturn('Body');
        $model->method('getRating')->willReturn(4.5);
        $model->method('getMedia')->willReturn('[]');
        $model->method('getMagentoStoreId')->willReturn(7);
        $model->method('getSubject')->willReturn('product');
        $model->method('getExternalProductId')->willReturn('42');
        $model->method('getFeraProductId')->willReturn('fpro-1');
        $model->method('getProductName')->willReturn('Product');
        $model->method('getState')->willReturn('approved');
        $model->method('getIsTest')->willReturn(false);
        $model->method('getFeraCreatedAt')->willReturn('2026-07-13 00:00:00');
        $model->method('getFeraUpdatedAt')->willReturn('2026-07-14 00:00:00');
        $collection->method('getFirstItem')->willReturn($model);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        /** @var Json&MockObject $json */
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('unserialize')->with('[]')->willReturn([]);

        self::assertSame(
            [
                'review_id' => 'review-1',
                'heading' => 'Heading',
                'body' => 'Body',
                'rating' => 4.5,
                'media' => [],
                'magento_store_id' => 7,
                'subject' => 'product',
                'external_product_id' => '42',
                'fera_product_id' => 'fpro-1',
                'product_name' => 'Product',
                'state' => 'approved',
                'is_test' => false,
                'fera_created_at' => '2026-07-13 00:00:00',
                'fera_updated_at' => '2026-07-14 00:00:00',
            ],
            $this->repository(
                $this->createMock(ReviewSnapshotResource::class),
                $this->createMock(ReviewSnapshotFactory::class),
                $collectionFactory,
                $json
            )->getByReviewId('review-1')
        );
    }

    public function testCountIncompleteUsesCollectionFilter(): void
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->expects(self::once())->method('addIncompleteReportingDataFilter')->willReturnSelf();
        $collection->expects(self::once())->method('getSize')->willReturn(3);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->expects(self::once())->method('create')->willReturn($collection);

        self::assertSame(
            3,
            $this->repository(
                $this->createMock(ReviewSnapshotResource::class),
                $this->createMock(ReviewSnapshotFactory::class),
                $collectionFactory,
                $this->createMock(Json::class)
            )->countIncomplete()
        );
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function snapshot(array $overrides = []): array
    {
        return array_replace([
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
        ], $overrides);
    }

    private function repository(
        ReviewSnapshotResource $resource,
        ReviewSnapshotFactory $snapshotFactory,
        CollectionFactory $collectionFactory,
        Json $json
    ): SnapshotRepository {
        return new SnapshotRepository($resource, $snapshotFactory, $collectionFactory, $json, new MediaNormalizer());
    }

    /**
     * @return ReviewSnapshot&MockObject
     */
    private function existingSnapshotModel(): ReviewSnapshot
    {
        $model = $this->createMock(ReviewSnapshot::class);
        $model->method('getId')->willReturn(1);
        $model->method('getFeraUpdatedAt')->willReturn('2026-07-13 00:00:00');
        $model->method('getBody')->willReturn('');
        $model->method('getRating')->willReturn(5.0);
        $model->method('getMedia')->willReturn('[]');
        $model->method('getMagentoStoreId')->willReturn(7);
        $model->method('getSubject')->willReturn('product');
        $model->method('getExternalProductId')->willReturn('42');
        $model->method('getFeraProductId')->willReturn('fpro-1');
        $model->method('getProductName')->willReturn('Product');
        $model->method('getState')->willReturn('approved');
        $model->method('getIsTest')->willReturn(false);
        $model->method('getFeraCreatedAt')->willReturn('2026-07-13 00:00:00');

        return $model;
    }
}
