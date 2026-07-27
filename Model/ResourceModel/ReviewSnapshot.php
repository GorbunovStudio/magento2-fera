<?php

declare(strict_types=1);

namespace Fera\Ai\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class ReviewSnapshot extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('fera_review_snapshots', 'id');
    }
}
