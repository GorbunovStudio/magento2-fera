<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraProductInterface;
use Fera\Ai\Model\FeraProductFactory;
use Fera\Ai\Model\ResourceModel\FeraProduct as FeraProductResource;
use Fera\Ai\Model\ResourceModel\FeraProduct\CollectionFactory as FeraProductCollectionFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use UnexpectedValueException;

class ProductExportManager
{
    public function __construct(
        private FeraProductResource $resource,
        private FeraProductFactory $factory,
        private FeraProductCollectionFactory $collectionFactory,
        private DateTime $dateTime
    ) {
    }

    public function isExported(int $productId, int $storeId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, (string) $productId);
        $collection->addFieldToFilter(FeraProductInterface::STORE_ID, (string) $storeId);
        $collection->setPageSize(1);
        return (bool) $collection->getSize();
    }

    public function getFeraId(int $productId, int $storeId): ?string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, (string) $productId);
        $collection->addFieldToFilter(FeraProductInterface::STORE_ID, (string) $storeId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }
        return $item->getFeraId();
    }

    /**
     * @param int[] $productIds
     * @param int $storeId
     * @return array<int,string> Map of product_id => fera_id
     */
    public function getFeraIdsByProductIds(array $productIds, int $storeId): array
    {
        if (empty($productIds)) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, ['in' => $productIds]);
        $collection->addFieldToFilter(FeraProductInterface::STORE_ID, (string) $storeId);
        $result = [];
        foreach ($collection->getItems() as $item) {
            $result[(int) $item->getProductId()] = (string) $item->getFeraId();
        }
        return $result;
    }

    public function saveSuccessfulExport(ProductInterface $product, string $feraId, int $storeId): void
    {
        if (!$product->getId()) {
            throw new UnexpectedValueException(
                'Incorrect type for Product ID: expected int, got ' . get_debug_type($product->getId())
            );
        }

        $model = $this->factory->create();
        $model->setProductId((int) $product->getId());
        $model->setFeraId($feraId);
        $model->setStoreId($storeId);
        $model->setExportedAt($this->dateTime->gmtDate());
        $this->resource->save($model);
    }

    public function deleteMapping(int $productId, int $storeId): void
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, (string) $productId);
        $collection->addFieldToFilter(FeraProductInterface::STORE_ID, (string) $storeId);
        
        foreach ($collection->getItems() as $item) {
            $this->resource->delete($item);
        }
    }
}
