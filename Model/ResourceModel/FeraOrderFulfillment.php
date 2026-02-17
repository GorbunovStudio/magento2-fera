<?php

declare(strict_types=1);

namespace Fera\Ai\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class FeraOrderFulfillment extends AbstractDb
{
    public const TABLE_NAME = 'fera_order_fulfillment';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'id');
    }
}
