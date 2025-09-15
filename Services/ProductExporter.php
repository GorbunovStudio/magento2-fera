<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Api\ApiClient\ProductsClientInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ProductExportManager;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ProductRepository;
use Magento\Framework\App\Area;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\InventoryApi\Api\GetSourceItemsBySkuInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Website;
use UnexpectedValueException;

/**
 * @phpstan-import-type Variant from ProductsClientInterface
 * @phpstan-import-type ProductData from ProductsClientInterface
 */
class ProductExporter implements ResettableDependenciesInterface
{
    public function __construct(
        private FeraHelper $helper,
        private StockResolverInterface $stockResolver,
        private GetProductSalableQtyInterface $getSalableQty,
        private AreProductsSalableInterface $areSalable,
        private EventManager $eventManager,
        private DataObjectFactory $dataObjectFactory,
        private ProductRepositoryInterface $productRepository,
        private ProductExportManager $productExportManager,
        private ProductsClientInterface $productsClient,
        private GetSourceItemsBySkuInterface $getSourceItemsBySku,
        private IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowedForProductType,
        private Emulation $emulation
    ) {
    }

    public function resetRepositories(): void
    {
        if ($this->productRepository instanceof ProductRepository) {
            $this->productRepository->_resetState();
        }
    }

    public function pushProduct(ProductInterface $product, int $storeId): void
    {
        $this->pushProducts([$product], $storeId);
    }

    /**
     * Push multiple products to the Fera API efficiently
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface[] $products
     * @param int $storeId
     */
    public function pushProducts(array $products, int $storeId): void
    {
        if (empty($products)) {
            return;
        }

        $this->validateProductsArray($products);

        $minimizeDataSharing = $this->helper->isMinimizeDataSharingEnabled($storeId);
        
        $ids = [];
        foreach ($products as $p) {
            $ids[] = (int) $p->getId();
        }
        $map = $this->productExportManager->getFeraIdsByProductIds($ids, $storeId);

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                throw new UnexpectedValueException(
                    'Expected instance of ' . Product::class . ', got ' . get_debug_type($product)
                );
            }
        
            // Reload the product to ensure the correct store context
            if ($storeId !== $product->getData('store_id')) {
                $product = $this->productRepository->getById($product->getId(), false, $storeId);
            }
            
            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                $productData = $this->buildProductData($product, $minimizeDataSharing);
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
            
            $externalId = (int) $productData['external_id'];
            $feraId = $map[$externalId] ?? null;
            $isUpdate = $feraId !== null && $feraId !== '';

            $resultFeraId = $this->sendProductData($productData, $feraId, $storeId);

            if (!$isUpdate) {
                $this->productExportManager->saveSuccessfulExport($product, $resultFeraId, $storeId);
            }
        }
    }

    /**
     * Build product data array for API call
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param bool $minimizeDataSharing
     * @return mixed[]
     * @phpstan-return ProductData
     */
    private function buildProductData(ProductInterface $product, bool $minimizeDataSharing): array
    {
        $thumb = $this->helper->getProductThumbnailUrl($product);
        
        if (!$product instanceof Product) {
            throw new UnexpectedValueException(
                'Expected instance of ' . Product::class . ', got ' . get_debug_type($product)
            );
        }

        $stockId = $this->getStockId($product);

        $productData = [
            'id' => $product->getId(),
            'external_id' => $product->getId(),
            'name' => $product->getName(),
            'created_at' => $this->helper->formatDate($product->getCreatedAt()),
            'modified_at' => $this->helper->formatDate($product->getUpdatedAt()),
            'url' => $product->getProductUrl(),
            'thumbnail_url' => $thumb,
            'needs_shipping' => $product->getTypeId() != 'virtual',
            'hidden' => (int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE,
            'tags' => [],
            'variants' => [],
            'platform_data' => [
                'sku' => $product->getSku(),
                'type' => $product->getTypeId(),
            ],
        ];

        if (!$minimizeDataSharing) {
            $productData['price'] = $product->getFinalPrice();
            $productData['status'] = $product->getStatus() == 1 ? 'published' : 'draft';

            if ($this->isInventoryManaged($product)) {
                $productData['stock'] = (float) $this->getSalableQtySafe($product->getSku(), $stockId);
                $productData['in_stock'] = $this->isSkuSalable($product->getSku(), $stockId);
            }

            $productData['platform_data']['regular_price'] = $product->getPrice();
        }

        if ($product->getTypeId() == 'configurable') {
            /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable $typeInstance */
            $typeInstance = $product->getTypeInstance();
            $cfgAttr = $typeInstance->getConfigurableAttributesAsArray($product);

            foreach ($typeInstance->getUsedProducts($product) as $subProduct) {
                if (!$subProduct instanceof Product) {
                    throw new UnexpectedValueException(
                        'Incorrect type for Product: expected ' . Product::class . ', got '
                        . get_debug_type($subProduct)
                    );
                }

                $variant = [
                    'id' => $subProduct->getId(),
                    'name' => $subProduct->getName(),
                    'created_at' => $this->helper->formatDate($subProduct->getCreatedAt()),
                    'modified_at' => $this->helper->formatDate($subProduct->getUpdatedAt()),
                    'platform_data' => [
                        'sku' => $subProduct->getSku(),
                    ],
                ];

                if (!$minimizeDataSharing) {
                    $variant['status'] = $subProduct->getStatus() == 1 ? 'published' : 'draft';
                    $variant['price'] = $subProduct->getFinalPrice();

                    if ($this->isInventoryManaged($subProduct)) {
                        $variant['stock'] = (float) $this->getSalableQtySafe($subProduct->getSku(), $stockId);
                        $variant['in_stock'] = $this->isSkuSalable($subProduct->getSku(), $stockId);
                    }
                }

                $variantImage = $this->helper->getProductThumbnailUrl($subProduct);
                if ($variantImage != $thumb && stripos($variantImage, '/placeholder') === false) {
                    $variant['thumbnail_url'] = $variantImage;
                }

                $variantAttrVals = [];
                foreach ($cfgAttr as $attr) {
                    $attrValIndex = $subProduct->getData($attr['attribute_code']);
                    foreach ($attr['values'] as $attrVal) {
                        if ($attrVal['value_index'] == $attrValIndex) {
                            $variantAttrVals[] = $attrVal['label'];
                        }
                    }
                }
                $variant['name'] = implode(' / ', $variantAttrVals);

                $productData['variants'][] = $variant;
            }
        }

        $productData = $this->enrichProductData($product, $productData);

        return $productData;
    }

    /**
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param array $productData
     * @phpstan-param ProductData $productData
     * @return array
     * @phpstan-return ProductData
     */
    private function enrichProductData(ProductInterface $product, array $productData): array
    {
        $payload = $this->dataObjectFactory->create(['data' => $productData]);
        $this->eventManager->dispatch('fera_export_product_data_ready', [
            'product' => $product,
            'productData' => $payload,
        ]);

        /** @phpstan-var ProductData $result */
        $result = $payload->getData();

        return $result;
    }

    /**
     * @param mixed[] $products
     * @phpstan-assert Product[] $products
     */
    private function validateProductsArray(array $products): void
    {
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                throw new UnexpectedValueException(
                    'Incorrect type for Product: expected ' . Product::class . ', got ' . get_debug_type($product)
                );
            }
        }
    }

    /**
     * @param mixed[] $data
     * @phpstan-param ProductData $data
     * @param string|null $feraId
     * @param int|null $storeId
     * @return string Fera ID
     */
    private function sendProductData(array $data, ?string $feraId, ?int $storeId = null): string
    {
        $isUpdate = $feraId !== null && $feraId !== '';

        if ($isUpdate) {
            $this->productsClient->update($feraId, $data, $storeId);
            $this->helper->debug('Successfully updated product ' . $data['external_id'] . ' in Fera API');
            return (string) $feraId;
        }

        $createdId = $this->productsClient->create($data, $storeId);
        $this->helper->debug('Successfully created product ' . $data['external_id'] . ' in Fera API');
        return $createdId;
    }

    private function getStockId(Product $product): int
    {
        $website = $product->getStore()->getWebsite();

        if (!$website instanceof Website) {
            return 0;
        }

        $websiteCode = $website->getCode();

        if (!is_string($websiteCode)) {
            throw new UnexpectedValueException(
                'Expected website code to be a string, got ' . get_debug_type($websiteCode)
            );
        }

        $stock = $this->stockResolver->execute(SalesChannelInterface::TYPE_WEBSITE, $websiteCode);
        return (int) $stock->getStockId();
    }

    private function isSkuSalable(string $sku, int $stockId): bool
    {
        $results = $this->areSalable->execute([$sku], $stockId);
        return isset($results[0]) ? (bool) $results[0]->isSalable() : false;
    }

    private function isInventoryManaged(Product $product): bool
    {
        $productType = $product->getTypeId();
        if (is_array($productType)) {
            $productType = reset($productType);
        }

        if (!$this->isSourceItemManagementAllowedForProductType->execute($productType)) {
            return false;
        }

        return count($this->getSourceItemsBySku->execute($product->getSku())) > 0;
    }

    private function getSalableQtySafe(string $sku, int $stockId): float
    {
        try {
            return (float) $this->getSalableQty->execute($sku, $stockId);
        } catch (\Throwable $e) {
            $this->helper->log('Error getting salable qty for SKU ' . $sku . ': ' . $e->getMessage());
            return 0;
        }
    }
}
