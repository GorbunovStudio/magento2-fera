<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use Fera\Ai\Api\Data\ReviewSnapshotInterface;
use Fera\Ai\Model\ReviewSnapshot as ReviewSnapshotModel;
use Fera\Ai\Model\ReviewSnapshotFactory;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot as ReviewSnapshotResource;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot\CollectionFactory as ReviewSnapshotCollectionFactory;
use InvalidArgumentException;
use Magento\Framework\Serialize\Serializer\Json;
use RuntimeException;

/**
 * @phpstan-import-type ReviewSnapshot from SnapshotBuilder
 */
class SnapshotRepository
{
    public function __construct(
        private ReviewSnapshotResource $resource,
        private ReviewSnapshotFactory $snapshotFactory,
        private ReviewSnapshotCollectionFactory $collectionFactory,
        private Json $json,
        private MediaNormalizer $mediaNormalizer
    ) {
    }

    /**
     * @return ReviewSnapshot|null
     */
    public function getByReviewId(string $reviewId): ?array
    {
        $model = $this->findByReviewId($reviewId);
        if (!$model->getId()) {
            return null;
        }

        return $this->toSnapshot($model);
    }

    /**
     * @param ReviewSnapshot $snapshot
     * @return ReviewSnapshot
     */
    public function save(array $snapshot): array
    {
        $model = $this->findByReviewId($snapshot['review_id']);
        if (!$model->getId()) {
            $model = $this->snapshotFactory->create();
            $this->applyNewSnapshot($model, $snapshot);
            $this->resource->save($model);
            return $this->toSnapshot($model);
        }

        $this->applyExistingSnapshot($model, $snapshot);
        if ($model->hasDataChanges()) {
            $this->resource->save($model);
        }

        return $this->toSnapshot($model);
    }

    public function countIncomplete(): int
    {
        $collection = $this->collectionFactory->create();
        $collection->addIncompleteReportingDataFilter();

        return (int) $collection->getSize();
    }

    /**
     * @return ReviewSnapshotModel
     */
    private function findByReviewId(string $reviewId): ReviewSnapshotModel
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(ReviewSnapshotInterface::REVIEW_ID, $reviewId);
        $collection->setPageSize(1);

        return $collection->getFirstItem();
    }

    /**
     * @param ReviewSnapshot $snapshot
     */
    private function applyNewSnapshot(ReviewSnapshotModel $model, array $snapshot): void
    {
        $model->setReviewId($snapshot['review_id']);
        $model->setHeading($snapshot['heading']);
        $model->setBody($snapshot['body']);
        $model->setRating($snapshot['rating']);
        $model->setMedia($this->encodeMedia($snapshot['media']));
        $model->setMagentoStoreId($snapshot['magento_store_id']);
        $model->setSubject($snapshot['subject']);
        $model->setExternalProductId($snapshot['external_product_id']);
        $model->setFeraProductId($snapshot['fera_product_id']);
        $model->setProductName($snapshot['product_name']);
        $model->setState($snapshot['state']);
        $model->setIsTest($snapshot['is_test']);
        $model->setFeraCreatedAt($snapshot['fera_created_at']);
        $model->setFeraUpdatedAt($snapshot['fera_updated_at']);
    }

    /**
     * @param ReviewSnapshot $snapshot
     */
    private function applyExistingSnapshot(ReviewSnapshotModel $model, array $snapshot): void
    {
        if ($this->shouldApplyMutableFields($model->getFeraUpdatedAt(), $snapshot['fera_updated_at'])) {
            $model->setHeading($snapshot['heading']);
            $model->setBody($snapshot['body']);
            $model->setRating($snapshot['rating']);
            $model->setMedia($this->encodeMedia($snapshot['media']));
            $model->setMagentoStoreId($snapshot['magento_store_id']);
            $model->setSubject($snapshot['subject']);
            $model->setExternalProductId($snapshot['external_product_id']);
            $model->setFeraProductId($snapshot['fera_product_id']);
            $model->setProductName($snapshot['product_name']);
            $model->setState($snapshot['state']);
            $model->setIsTest($snapshot['is_test']);
            $model->setFeraUpdatedAt($snapshot['fera_updated_at']);
        }

        if ($model->getFeraCreatedAt() === null && $snapshot['fera_created_at'] !== null) {
            $model->setFeraCreatedAt($snapshot['fera_created_at']);
        }
    }

    private function shouldApplyMutableFields(?string $storedVersion, ?string $incomingVersion): bool
    {
        return $storedVersion === null || ($incomingVersion !== null && $incomingVersion >= $storedVersion);
    }

    /**
     * @return ReviewSnapshot
     */
    private function toSnapshot(ReviewSnapshotModel $model): array
    {
        return [
            'review_id' => $model->getReviewId(),
            'heading' => $model->getHeading(),
            'body' => $model->getBody(),
            'rating' => $model->getRating(),
            'media' => $this->decodeMedia($model->getMedia()),
            'magento_store_id' => $model->getMagentoStoreId(),
            'subject' => $model->getSubject(),
            'external_product_id' => $model->getExternalProductId(),
            'fera_product_id' => $model->getFeraProductId(),
            'product_name' => $model->getProductName(),
            'state' => $model->getState(),
            'is_test' => $model->getIsTest(),
            'fera_created_at' => $model->getFeraCreatedAt(),
            'fera_updated_at' => $model->getFeraUpdatedAt(),
        ];
    }

    /**
     * @param list<array{id: string, url: string}> $media
     */
    private function encodeMedia(array $media): string
    {
        $encoded = $this->json->serialize($this->mediaNormalizer->normalize($media));
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to encode review snapshot media');
        }

        return $encoded;
    }

    /**
     * @return list<array{id: string, url: string}>
     */
    private function decodeMedia(string $media): array
    {
        try {
            $decoded = $this->json->unserialize($media);
        } catch (InvalidArgumentException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $this->mediaNormalizer->normalize($decoded);
    }
}
