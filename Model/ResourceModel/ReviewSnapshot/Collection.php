<?php

declare(strict_types=1);

namespace Fera\Ai\Model\ResourceModel\ReviewSnapshot;

use Fera\Ai\Model\ReviewSnapshot as ReviewSnapshotModel;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot as ReviewSnapshotResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * @method \Fera\Ai\Model\ReviewSnapshot getFirstItem()
 * @method \Fera\Ai\Model\ReviewSnapshot getLastItem()
 * @method \Fera\Ai\Model\ReviewSnapshot[] getItems()
 * @method \Fera\Ai\Model\ReviewSnapshot[] getItemsByColumnValue($column, $value)
 */
class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(ReviewSnapshotModel::class, ReviewSnapshotResource::class);
    }

    public function addIncompleteReportingDataFilter(): static
    {
        $this->getSelect()->where(
            '(main_table.fera_created_at IS NULL OR main_table.magento_store_id IS NULL '
            . 'OR main_table.subject IS NULL OR (main_table.subject = ? '
            . 'AND main_table.fera_product_id IS NULL AND main_table.external_product_id IS NULL))',
            'product'
        );

        return $this;
    }
}
