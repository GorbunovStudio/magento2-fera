<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use Fera\Ai\Api\Data\ReviewSnapshotInterface;
use Fera\Ai\Model\ReviewSnapshot as ReviewSnapshotModel;
use Fera\Ai\Model\ReviewSnapshotFactory;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot as ReviewSnapshotResource;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot\CollectionFactory as ReviewSnapshotCollectionFactory;
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
     */
    public function save(array $snapshot): void
    {
        $model = $this->findByReviewId($snapshot['review_id']);
        if (!$model->getId()) {
            $model = $this->snapshotFactory->create();
            $this->applyNewSnapshot($model, $snapshot);
            $this->resource->save($model);
            return;
        }

        if ($this->applyExistingSnapshot($model, $snapshot)) {
            $this->resource->save($model);
        }
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
    private function applyExistingSnapshot(ReviewSnapshotModel $model, array $snapshot): bool
    {
        $changed = false;
        if ($this->shouldApplyMutableFields($model->getFeraUpdatedAt(), $snapshot['fera_updated_at'])) {
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::HEADING, $snapshot['heading']);
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::BODY, $snapshot['body']) || $changed;
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::RATING, $snapshot['rating']) || $changed;
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::MEDIA, $this->encodeMedia($snapshot['media'])) || $changed;
            $changed = $this->setIfChanged(
                $model,
                ReviewSnapshotInterface::MAGENTO_STORE_ID,
                $snapshot['magento_store_id']
            ) || $changed;
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::SUBJECT, $snapshot['subject']) || $changed;
            $changed = $this->setIfChanged(
                $model,
                ReviewSnapshotInterface::EXTERNAL_PRODUCT_ID,
                $snapshot['external_product_id']
            ) || $changed;
            $changed = $this->setIfChanged(
                $model,
                ReviewSnapshotInterface::FERA_PRODUCT_ID,
                $snapshot['fera_product_id']
            ) || $changed;
            $changed = $this->setIfChanged(
                $model,
                ReviewSnapshotInterface::PRODUCT_NAME,
                $snapshot['product_name']
            ) || $changed;
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::STATE, $snapshot['state']) || $changed;
            $changed = $this->setIfChanged($model, ReviewSnapshotInterface::IS_TEST, $snapshot['is_test']) || $changed;
            $changed = $this->setIfChanged(
                $model,
                ReviewSnapshotInterface::FERA_UPDATED_AT,
                $snapshot['fera_updated_at']
            ) || $changed;
        }

        if ($model->getFeraCreatedAt() === null && $snapshot['fera_created_at'] !== null) {
            $changed = $this->setIfChanged(
                $model,
                ReviewSnapshotInterface::FERA_CREATED_AT,
                $snapshot['fera_created_at']
            ) || $changed;
        }

        return $changed;
    }

    private function shouldApplyMutableFields(?string $storedVersion, ?string $incomingVersion): bool
    {
        return $storedVersion === null || ($incomingVersion !== null && $incomingVersion >= $storedVersion);
    }

    private function setIfChanged(ReviewSnapshotModel $model, string $field, mixed $value): bool
    {
        if ($this->currentValue($model, $field) === $value) {
            return false;
        }

        $model->setData($field, $value);
        return true;
    }

    private function currentValue(ReviewSnapshotModel $model, string $field): mixed
    {
        return match ($field) {
            ReviewSnapshotInterface::HEADING => $model->getHeading(),
            ReviewSnapshotInterface::BODY => $model->getBody(),
            ReviewSnapshotInterface::RATING => $model->getRating(),
            ReviewSnapshotInterface::MEDIA => $model->getMedia(),
            ReviewSnapshotInterface::MAGENTO_STORE_ID => $model->getMagentoStoreId(),
            ReviewSnapshotInterface::SUBJECT => $model->getSubject(),
            ReviewSnapshotInterface::EXTERNAL_PRODUCT_ID => $model->getExternalProductId(),
            ReviewSnapshotInterface::FERA_PRODUCT_ID => $model->getFeraProductId(),
            ReviewSnapshotInterface::PRODUCT_NAME => $model->getProductName(),
            ReviewSnapshotInterface::STATE => $model->getState(),
            ReviewSnapshotInterface::IS_TEST => $model->getIsTest(),
            ReviewSnapshotInterface::FERA_CREATED_AT => $model->getFeraCreatedAt(),
            ReviewSnapshotInterface::FERA_UPDATED_AT => $model->getFeraUpdatedAt(),
            default => throw new \LogicException('Unsupported review snapshot field ' . $field),
        };
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
        $decoded = $this->json->unserialize($media);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->mediaNormalizer->normalize($decoded);
    }
}
