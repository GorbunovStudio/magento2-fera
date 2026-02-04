<?php

declare(strict_types=1);

namespace Fera\Ai\Model\ResourceModel\FeraOrderFulfillment;

use Fera\Ai\Model\FeraOrderFulfillment as FeraOrderFulfillmentModel;
use Fera\Ai\Model\ResourceModel\FeraOrderFulfillment as FeraOrderFulfillmentResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method \Fera\Ai\Model\FeraOrderFulfillment getFirstItem()
 * @method \Fera\Ai\Model\FeraOrderFulfillment getLastItem()
 * @method \Fera\Ai\Model\FeraOrderFulfillment getItemById($idValue)
 * @method \Fera\Ai\Model\FeraOrderFulfillment[] getItems()
 * @method \Fera\Ai\Model\FeraOrderFulfillment[] getItemsByColumnValue($column, $value)
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(FeraOrderFulfillmentModel::class, FeraOrderFulfillmentResource::class);
    }
}
