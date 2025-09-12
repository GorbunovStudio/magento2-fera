<?php

namespace Fera\Ai\Model\ResourceModel\FeraProduct;

use Fera\Ai\Model\FeraProduct as FeraProductModel;
use Fera\Ai\Model\ResourceModel\FeraProduct as FeraProductResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method \Fera\Ai\Model\FeraProduct getFirstItem()
 * @method \Fera\Ai\Model\FeraProduct getLastItem()
 * @method \Fera\Ai\Model\FeraProduct[] getItems()
 * @method \Fera\Ai\Model\FeraProduct[] getItemsByColumnValue($column, $value)
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FeraProductModel::class, FeraProductResource::class);
    }
}
