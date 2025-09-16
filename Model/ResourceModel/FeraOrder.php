<?php

declare(strict_types=1);

namespace Fera\Ai\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class FeraOrder extends AbstractDb
{
    public const TABLE_NAME = 'fera_orders';

    protected function _construct(): void
    {
        $this->_init(self::TABLE_NAME, 'id');
    }
}
