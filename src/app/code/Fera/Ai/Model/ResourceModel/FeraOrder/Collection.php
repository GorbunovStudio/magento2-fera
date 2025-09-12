<?php

namespace Fera\Ai\Model\ResourceModel\FeraOrder;

use Fera\Ai\Model\FeraOrder as FeraOrderModel;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method \Fera\Ai\Model\FeraOrder getFirstItem()
 * @method \Fera\Ai\Model\FeraOrder getLastItem()
 * @method \Fera\Ai\Model\FeraOrder[] getItems()
 * @method \Fera\Ai\Model\FeraOrder[] getItemsByColumnValue($column, $value)
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FeraOrderModel::class, FeraOrderResource::class);
    }
}
