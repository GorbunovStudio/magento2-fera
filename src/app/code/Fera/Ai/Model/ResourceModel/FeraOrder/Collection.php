<?php

namespace Fera\Ai\Model\ResourceModel\FeraOrder;

use Fera\Ai\Model\FeraOrder as FeraOrderModel;
use Fera\Ai\Model\ResourceModel\FeraOrder as FeraOrderResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FeraOrderModel::class, FeraOrderResource::class);
    }
}
