<?php

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\FeraProductInterface;
use Fera\Ai\Model\ResourceModel\FeraProduct as FeraProductResource;
use Fera\Ai\Model\ResourceModel\FeraProduct\CollectionFactory as FeraProductCollectionFactory;
use Fera\Ai\Model\FeraProductFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use UnexpectedValueException;

class ProductExportManager
{
    private FeraProductResource $resource;
    private FeraProductFactory $factory;
    private FeraProductCollectionFactory $collectionFactory;
    private DateTime $dateTime;

    public function __construct(
        FeraProductResource $resource,
        FeraProductFactory $factory,
        FeraProductCollectionFactory $collectionFactory,
        DateTime $dateTime
    ) {
        $this->resource = $resource;
        $this->factory = $factory;
        $this->collectionFactory = $collectionFactory;
        $this->dateTime = $dateTime;
    }

    public function isExported(int $productId): bool
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, (string) $productId);
        $collection->setPageSize(1);
        return (bool) $collection->getSize();
    }

    public function getFeraId(int $productId): ?string
    {
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, (string) $productId);
        $collection->setPageSize(1);
        $item = $collection->getFirstItem();
        if (!$item->getId()) {
            return null;
        }
        return $item->getFeraId();
    }

    /**
     * @param int[] $productIds
     * @return array<int,string> Map of product_id => fera_id
     */
    public function getFeraIdsByProductIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $collection = $this->collectionFactory->create();
        $collection->addFieldToFilter(FeraProductInterface::PRODUCT_ID, ['in' => $productIds]);
        $result = [];
        foreach ($collection->getItems() as $item) {
            $result[(int) $item->getProductId()] = (string) $item->getFeraId();
        }
        return $result;
    }

    public function saveSuccessfulExport(ProductInterface $product, string $feraId): void
    {
        if (!$product->getId()) {
            throw new UnexpectedValueException(
                'Incorrect type for Product ID: expected int, got ' . gettype($product->getId())
            );
        }

        $model = $this->factory->create();
        $model->setProductId((int) $product->getId());
        $model->setFeraId($feraId);
        $model->setExportedAt($this->dateTime->gmtDate());
        $this->resource->save($model);
    }
}
