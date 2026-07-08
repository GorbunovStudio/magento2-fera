<?php

declare(strict_types=1);

namespace Fera\Ai\Setup\Patch\Schema;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;

class ConvertReviewSnapshotsToUtf8mb4 implements SchemaPatchInterface
{
    private const TABLE_NAME = 'fera_review_snapshots';
    private const CHARSET = 'utf8mb4';
    private const COLLATION = 'utf8mb4_general_ci';

    public function __construct(private ModuleDataSetupInterface $moduleDataSetup)
    {
    }

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $tableName = $this->moduleDataSetup->getTable(self::TABLE_NAME);
        if ($connection->isTableExists($tableName)) {
            $connection->query(
                sprintf(
                    'ALTER TABLE %s CONVERT TO CHARACTER SET %s COLLATE %s',
                    $connection->quoteIdentifier($tableName),
                    self::CHARSET,
                    self::COLLATION
                )
            );
        }

        $connection->endSetup();

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
