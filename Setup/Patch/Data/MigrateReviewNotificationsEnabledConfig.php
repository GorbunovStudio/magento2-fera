<?php

declare(strict_types=1);

namespace Fera\Ai\Setup\Patch\Data;

use Fera\Ai\Interface\ConfigOptionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class MigrateReviewNotificationsEnabledConfig implements DataPatchInterface
{
    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private ResourceConnection $resourceConnection
    ) {
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

    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $connection->startSetup();

        $tableName = $this->resourceConnection->getTableName('core_config_data');
        $select = $connection->select()
            ->from($tableName, ['scope', 'scope_id', 'value'])
            ->where('path = ?', ConfigOptionInterface::LEGACY_REVIEW_NOTIFICATIONS_ENABLED);

        foreach ($connection->fetchAll($select) as $row) {
            $existingTarget = $connection->fetchOne(
                $connection->select()
                    ->from($tableName, ['config_id'])
                    ->where('path = ?', ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED)
                    ->where('scope = ?', $row['scope'])
                    ->where('scope_id = ?', $row['scope_id'])
                    ->limit(1)
            );

            if ($existingTarget !== false) {
                continue;
            }

            $connection->insert(
                $tableName,
                [
                    'scope' => $row['scope'],
                    'scope_id' => $row['scope_id'],
                    'path' => ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED,
                    'value' => $row['value'],
                ]
            );
        }

        $connection->endSetup();

        return $this;
    }
}
