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
use InvalidArgumentException;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SnapshotRepositoryTest extends TestCase
{
    public function testSaveCreatesNewEntityThroughFactory(): void
    {
        $newSnapshot = $this->existingSnapshotModel(['getId' => null]);
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('serialize')->with([])->willReturn('[]');
        $setup = $this->repositoryFor($newSnapshot, $json);
        $setup['factory']->expects(self::once())->method('create')->willReturn($newSnapshot);
        $setup['resource']->expects(self::once())->method('save')->with($newSnapshot);

        $setup['repository']->save($this->snapshot());
    }

    public function testStaleSnapshotDoesNotOverwriteExistingEntity(): void
    {
        $setup = $this->repositoryFor($this->existingSnapshotModel([
            'getFeraUpdatedAt' => '2026-07-14 00:00:00',
            'getExternalOrderId' => 'stored-order',
        ]));
        $setup['resource']->expects(self::never())->method('save');

        $setup['repository']->save($this->snapshot([
            'external_order_id' => 'stale-order',
            'fera_updated_at' => '2026-07-12 00:00:00',
        ]));
    }

    public function testEqualSourceVersionUpdatesChangedMutableField(): void
    {
        $existing = $this->existingSnapshotModel(['getHeading' => 'Old heading']);
        $existing->expects(self::once())
            ->method('setHeading')
            ->with('New heading')
            ->willReturnSelf();
        $existing->method('hasDataChanges')->willReturn(true);
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('serialize')->with([])->willReturn('[]');
        $setup = $this->repositoryFor($existing, $json);
        $setup['resource']->expects(self::once())->method('save')->with($existing);

        $setup['repository']->save($this->snapshot(['heading' => 'New heading']));
    }

    public function testNewerSourceVersionReturnsAcceptedSnapshot(): void
    {
        $existing = $this->loadedSnapshotModel([
            'heading' => 'Old heading',
            'rating' => '4.00',
            'is_test' => '0',
            'external_order_id' => 'old-order',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ]);
        $snapshot = $this->snapshot([
            'heading' => 'New heading',
            'rating' => 4.5,
            'is_test' => true,
            'external_order_id' => 'new-order',
            'fera_updated_at' => '2026-07-14 00:00:00',
        ]);
        $setup = $this->repositoryFor($existing);
        $setup['resource']->expects(self::once())->method('save')->with($existing);

        self::assertSame(
            $snapshot,
            $setup['repository']->save($snapshot)
        );
    }

    public function testUnchangedNumericAndBooleanValuesDoNotSave(): void
    {
        $existing = $this->loadedSnapshotModel([
            'rating' => '5.00',
            'magento_store_id' => '7',
            'is_test' => '0',
        ]);
        $snapshot = $this->snapshot();
        $setup = $this->repositoryFor($existing);
        $setup['resource']->expects(self::never())->method('save');

        self::assertSame(
            $snapshot,
            $setup['repository']->save($snapshot)
        );
    }

    public function testStaleSnapshotCanFillMissingCreationTimestamp(): void
    {
        $existing = $this->existingSnapshotModel([
            'getFeraUpdatedAt' => '2026-07-14 00:00:00',
            'getFeraCreatedAt' => null,
        ]);
        $existing->expects(self::once())
            ->method('setFeraCreatedAt')
            ->with('2026-07-13 00:00:00')
            ->willReturnSelf();
        $existing->method('hasDataChanges')->willReturn(true);
        $setup = $this->repositoryFor($existing);
        $setup['resource']->expects(self::once())->method('save')->with($existing);

        $setup['repository']->save($this->snapshot(['fera_updated_at' => '2026-07-12 00:00:00']));
    }

    public function testStaleSnapshotFillsMissingExternalOrderIdWithoutOverwritingReviewState(): void
    {
        $existing = $this->loadedSnapshotModel([
            'heading' => 'Newer heading',
            'external_order_id' => null,
            'fera_updated_at' => '2026-07-14 00:00:00',
        ]);
        $setup = $this->repositoryFor($existing);
        $setup['resource']->expects(self::once())->method('save')->with($existing);

        $effective = $setup['repository']->save($this->snapshot([
            'heading' => 'Stale heading',
            'external_order_id' => 'backfill-order',
            'fera_updated_at' => '2026-07-12 00:00:00',
        ]));

        self::assertSame('backfill-order', $effective['external_order_id']);
        self::assertSame('Newer heading', $effective['heading']);
        self::assertSame('2026-07-14 00:00:00', $effective['fera_updated_at']);
    }

    public function testNewerSnapshotPreservesStoredExternalOrderIdWhenIncomingValueIsOmitted(): void
    {
        $existing = $this->loadedSnapshotModel([
            'external_order_id' => 'stored-order',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ]);
        $setup = $this->repositoryFor($existing);
        $setup['resource']->expects(self::never())->method('save');

        $effective = $setup['repository']->save($this->snapshot([
            'external_order_id' => null,
            'fera_updated_at' => '2026-07-14 00:00:00',
        ]));

        self::assertSame('stored-order', $effective['external_order_id']);
    }

    public function testGetByReviewIdMapsEntityToExistingSnapshotContract(): void
    {
        $model = $this->existingSnapshotModel([
            'getHeading' => 'Heading',
            'getBody' => 'Body',
            'getRating' => 4.5,
            'getFeraUpdatedAt' => '2026-07-14 00:00:00',
        ]);
        $json = $this->createMock(Json::class);
        $json->expects(self::once())->method('unserialize')->with('[]')->willReturn([]);
        $setup = $this->repositoryFor($model, $json);
        $setup['collection']->expects(self::once())
            ->method('addFieldToFilter')
            ->with(ReviewSnapshotInterface::REVIEW_ID, 'review-1')
            ->willReturnSelf();
        $setup['collection']->expects(self::once())->method('setPageSize')->with(1)->willReturnSelf();

        self::assertSame(
            [
                'review_id' => 'review-1',
                'heading' => 'Heading',
                'body' => 'Body',
                'rating' => 4.5,
                'media' => [],
                'magento_store_id' => 7,
                'subject' => 'product',
                'external_order_id' => 'order-1',
                'external_product_id' => '42',
                'fera_product_id' => 'fpro-1',
                'product_name' => 'Product',
                'state' => 'approved',
                'is_test' => false,
                'fera_created_at' => '2026-07-13 00:00:00',
                'fera_updated_at' => '2026-07-14 00:00:00',
            ],
            $setup['repository']->getByReviewId('review-1')
        );
    }

    public function testGetByReviewIdToleratesMalformedStoredMedia(): void
    {
        $json = $this->createMock(Json::class);
        $json->expects(self::once())
            ->method('unserialize')
            ->with('malformed')
            ->willThrowException(new InvalidArgumentException('Malformed JSON'));
        $setup = $this->repositoryFor($this->existingSnapshotModel(['getMedia' => 'malformed']), $json);

        self::assertSame([], $setup['repository']->getByReviewId('review-1')['media']);
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
            'external_order_id' => 'order-1',
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
     * @return array{
     *     repository: SnapshotRepository,
     *     resource: ReviewSnapshotResource,
     *     factory: ReviewSnapshotFactory,
     *     collection: Collection
     * }
     */
    private function repositoryFor(ReviewSnapshot $model, ?Json $json = null): array
    {
        /** @var Collection&MockObject $collection */
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('setPageSize')->willReturnSelf();
        $collection->method('getFirstItem')->willReturn($model);

        /** @var CollectionFactory&MockObject $collectionFactory */
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);
        /** @var ReviewSnapshotResource&MockObject $resource */
        $resource = $this->createMock(ReviewSnapshotResource::class);
        /** @var ReviewSnapshotFactory&MockObject $factory */
        $factory = $this->createMock(ReviewSnapshotFactory::class);

        return [
            'repository' => $this->repository($resource, $factory, $collectionFactory, $json ?? new Json()),
            'resource' => $resource,
            'factory' => $factory,
            'collection' => $collection,
        ];
    }

    /**
     * @return ReviewSnapshot&MockObject
     */
    private function existingSnapshotModel(array $overrides = []): ReviewSnapshot
    {
        $model = $this->createMock(ReviewSnapshot::class);
        foreach (array_replace([
            'getId' => 1,
            'getReviewId' => 'review-1',
            'getHeading' => '',
            'getBody' => '',
            'getRating' => 5.0,
            'getMedia' => '[]',
            'getMagentoStoreId' => 7,
            'getSubject' => 'product',
            'getExternalOrderId' => 'order-1',
            'getExternalProductId' => '42',
            'getFeraProductId' => 'fpro-1',
            'getProductName' => 'Product',
            'getState' => 'approved',
            'getIsTest' => false,
            'getFeraCreatedAt' => '2026-07-13 00:00:00',
            'getFeraUpdatedAt' => '2026-07-13 00:00:00',
        ], $overrides) as $method => $value) {
            $model->method($method)->willReturn($value);
        }
        foreach ([
            'setReviewId',
            'setHeading',
            'setBody',
            'setRating',
            'setMedia',
            'setMagentoStoreId',
            'setSubject',
            'setExternalOrderId',
            'setExternalProductId',
            'setFeraProductId',
            'setProductName',
            'setState',
            'setIsTest',
            'setFeraUpdatedAt',
            'setFeraCreatedAt',
        ] as $method) {
            $model->method($method)->willReturnSelf();
        }

        return $model;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function loadedSnapshotModel(array $overrides = []): ReviewSnapshot
    {
        $context = $this->createMock(Context::class);
        $context->method('getAppState')->willReturn($this->createMock(State::class));
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $context->method('getCacheManager')->willReturn($this->createMock(CacheInterface::class));
        $context->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
        $context->method('getActionValidator')->willReturn($this->createMock(RemoveAction::class));
        $resource = $this->createMock(ReviewSnapshotResource::class);
        $resource->method('getIdFieldName')->willReturn('id');
        $model = new ReviewSnapshot($context, new Registry(), $resource);
        $model->setData(array_replace([
            'id' => 1,
            'review_id' => 'review-1',
            'heading' => '',
            'body' => '',
            'rating' => '5.00',
            'media' => '[]',
            'magento_store_id' => '7',
            'subject' => 'product',
            'external_order_id' => 'order-1',
            'external_product_id' => '42',
            'fera_product_id' => 'fpro-1',
            'product_name' => 'Product',
            'state' => 'approved',
            'is_test' => '0',
            'fera_created_at' => '2026-07-13 00:00:00',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ], $overrides));
        $model->setOrigData();
        $model->setDataChanges(false);

        return $model;
    }
}
