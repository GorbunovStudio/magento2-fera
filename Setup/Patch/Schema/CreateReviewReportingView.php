<?php

declare(strict_types=1);

namespace Fera\Ai\Setup\Patch\Schema;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;

class CreateReviewReportingView implements SchemaPatchInterface
{
    private const SNAPSHOT_TABLE = 'fera_review_snapshots';
    private const STORE_TABLE = 'store';
    private const VIEW_NAME = 'fera_product_review_reporting';

    public function __construct(private ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        try {
            $snapshotTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable(self::SNAPSHOT_TABLE));
            $storeTable = $connection->quoteIdentifier($this->moduleDataSetup->getTable(self::STORE_TABLE));
            $viewName = $connection->quoteIdentifier(self::VIEW_NAME);

            $connection->query(sprintf(
                'CREATE OR REPLACE VIEW %s AS '
                . 'SELECT snapshots.magento_store_id, stores.name AS magento_store_name, '
                . 'snapshots.subject, snapshots.fera_product_id, snapshots.external_product_id, '
                . 'snapshots.product_name, snapshots.rating, snapshots.state, snapshots.is_test, '
                . 'snapshots.fera_created_at, snapshots.fera_updated_at '
                . 'FROM %s AS snapshots '
                . 'INNER JOIN %s AS stores ON stores.store_id = snapshots.magento_store_id '
                . "WHERE snapshots.subject = 'product' "
                . 'AND snapshots.fera_created_at IS NOT NULL '
                . 'AND (snapshots.fera_product_id IS NOT NULL OR snapshots.external_product_id IS NOT NULL)',
                $viewName,
                $snapshotTable,
                $storeTable
            ));
        } finally {
            $connection->endSetup();
        }

        return $this;
    }

    /**
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
