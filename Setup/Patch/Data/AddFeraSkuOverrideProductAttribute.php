<?php

declare(strict_types=1);

namespace Fera\Ai\Setup\Patch\Data;

use Fera\Ai\Api\Data\ProductAttributeCodeInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

class AddFeraSkuOverrideProductAttribute implements DataPatchInterface
{
    private const ATTRIBUTE_LABEL = 'SKU Override';

    private const TARGET_ATTRIBUTE_SET_NAME = 'Default';
    private const TARGET_ATTRIBUTE_GROUP_NAME = 'Fera';

    public function __construct(
        private ModuleDataSetupInterface $moduleDataSetup,
        private EavSetupFactory $eavSetupFactory
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

        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $existingAttributeId = (int) $eavSetup->getAttributeId(Product::ENTITY, ProductAttributeCodeInterface::FERA_SKU_OVERRIDE);
        if ($existingAttributeId === 0) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                ProductAttributeCodeInterface::FERA_SKU_OVERRIDE,
                [
                    'type' => 'varchar',
                    'label' => self::ATTRIBUTE_LABEL,
                    'input' => 'text',
                    'required' => false,
                    'user_defined' => true,
                    'global' => ScopedAttributeInterface::SCOPE_STORE,
                    'visible' => true,
                    'visible_on_front' => false,
                    'searchable' => false,
                    'filterable' => false,
                    'comparable' => false,
                    'used_in_product_listing' => false,
                    'unique' => false,
                    'system' => 0,
                ]
            );
        }

        $attributeSetId = (int)$eavSetup->getAttributeSetId(Product::ENTITY, self::TARGET_ATTRIBUTE_SET_NAME);
        if ($attributeSetId === 0) {
            $attributeSetId = (int)$eavSetup->getDefaultAttributeSetId(Product::ENTITY);
        }

        $attributeGroupId = (int)$eavSetup->getAttributeGroupId(
            Product::ENTITY,
            $attributeSetId,
            self::TARGET_ATTRIBUTE_GROUP_NAME
        );

        if ($attributeGroupId === 0) {
            $eavSetup->addAttributeGroup(
                Product::ENTITY,
                $attributeSetId,
                self::TARGET_ATTRIBUTE_GROUP_NAME,
                999
            );
        }

        $eavSetup->addAttributeToGroup(
            Product::ENTITY,
            $attributeSetId,
            self::TARGET_ATTRIBUTE_GROUP_NAME,
            ProductAttributeCodeInterface::FERA_SKU_OVERRIDE,
            999
        );

        $connection->endSetup();
    }
}
