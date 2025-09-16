<?php

declare(strict_types=1);

namespace Fera\Ai\Setup\Patch\Data;

use Fera\Ai\Interface\ConfigOptionInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class EncryptFeraSecretKey implements DataPatchInterface
{
    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private ResourceConnection $resourceConnection,
        private EncryptorInterface $encryptor
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
            ->from($tableName, ['config_id', 'value', 'scope', 'scope_id'])
            ->where('path = ?', ConfigOptionInterface::SECRET_KEY)
            ->where('value IS NOT NULL')
            ->where('value != ?', '');

        $configRows = $connection->fetchAll($select);

        foreach ($configRows as $row) {
            $value = $row['value'];
            
            $decryptedValue = $this->encryptor->decrypt($value);
            if (strlen($decryptedValue) === 67) {
                // Value is already encrypted (decryption returned proper key), skip it
                continue;
            }
            
            $encryptedValue = $this->encryptor->encrypt($value);
            
            $connection->update(
                $tableName,
                ['value' => $encryptedValue],
                ['config_id = ?' => $row['config_id']]
            );
        }

        $connection->endSetup();

        return $this;
    }
}
