<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ProductExportManager;
use Fera\Ai\Services\ApiClient;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\GetProductSalableQtyInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Model\Website;
use RuntimeException;
use UnexpectedValueException;

/**
 * @phpstan-type Variant array{
 *     id: int|string,
 *     name: string,
 *     status: string,
 *     created_at: string,
 *     modified_at: string,
 *     stock: float,
 *     in_stock: bool,
 *     price: float,
 *     platform_data: array{sku: string},
 *     thumbnail_url?: string
 * }
 * @phpstan-type ProductData array{
 *     id: int|string,
 *     external_id: int|string,
 *     name: string,
 *     price: float,
 *     status: string,
 *     created_at: string,
 *     modified_at: string,
 *     stock: float,
 *     in_stock: bool,
 *     url: string,
 *     thumbnail_url: string,
 *     needs_shipping: bool,
 *     hidden: bool,
 *     tags: string[],
 *     variants: Variant[],
 *     platform_data: array{sku: string, type: string|mixed[], regular_price: float}
 * }
 */
class ProductExporter
{
    protected const API_ENDPOINT_PRODUCTS = 'v3/private/products';

    public function __construct(
        private FeraHelper $helper,
        private StockResolverInterface $stockResolver,
        private GetProductSalableQtyInterface $getSalableQty,
        private AreProductsSalableInterface $areSalable,
        private EventManager $eventManager,
        private DataObjectFactory $dataObjectFactory,
        private ProductRepositoryInterface $productRepository,
        private ProductExportManager $productExportManager,
        private ApiClient $apiClient
    ) {
    }

    public function pushProduct(ProductInterface $product, int $storeId = null): void
    {
        $this->pushProducts([$product], $storeId);
    }

    /**
     * Push multiple products to the Fera API efficiently
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface[] $products
     * @param int|null $storeId
     */
    public function pushProducts(array $products, $storeId = null): void
    {
        if (empty($products)) {
            return;
        }

        $this->validateProductsArray($products);
        
        $ids = [];
        foreach ($products as $p) {
            $ids[] = (int) $p->getId();
        }
        $map = $this->productExportManager->getFeraIdsByProductIds($ids);

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                throw new \UnexpectedValueException(
                    'Expected instance of ' . Product::class . ', got ' . get_debug_type($product)
                );
            }
        
            // Reload the product to ensure the correct store context
            if ($storeId !== $product->getData('store_id')) {
                $product = $this->productRepository->getById($product->getId(), false, $storeId);
            }
            
            $productData = $this->buildProductData($product);
            $externalId = (int) $productData['external_id'];
            $feraId = $map[$externalId] ?? null;
            $isUpdate = $feraId !== null && $feraId !== '';

            $resultFeraId = $this->sendProductData($productData, $feraId, $storeId);

            if (!$isUpdate) {
                $this->productExportManager->saveSuccessfulExport($product, $resultFeraId);
            }
        }
    }

    /**
     * Build product data array for API call
     *
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @return mixed[]
     * @phpstan-return ProductData
     */
    private function buildProductData(ProductInterface $product): array
    {
        $thumb = $this->helper->getProductThumbnailUrl($product);
        
        if (!$product instanceof Product) {
            throw new \UnexpectedValueException(
                'Expected instance of ' . Product::class . ', got ' . get_debug_type($product)
            );
        }

        $stockId = $this->getStockId($product);
        $stockQuantity = $this->getSalableQty->execute($product->getSku(), $stockId);
        $inStock = $this->isSkuSalable($product->getSku(), $stockId);

        $productData = [
            'id' => $product->getId(),
            'external_id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getFinalPrice(),
            'status' => $product->getStatus() == 1 ? 'published' : 'draft',
            'created_at' => $this->helper->formatDate($product->getCreatedAt()),
            'modified_at' => $this->helper->formatDate($product->getUpdatedAt()),
            'stock' => $stockQuantity,
            'in_stock' => $inStock,
            'url' => $product->getProductUrl(),
            'thumbnail_url' => $thumb,
            'needs_shipping' => $product->getTypeId() != 'virtual',
            'hidden' => (int) $product->getVisibility() === Visibility::VISIBILITY_NOT_VISIBLE,
            'tags' => [],
            'variants' => [],
            'platform_data' => [
                'sku' => $product->getSku(),
                'type' => $product->getTypeId(),
                'regular_price' => $product->getPrice(),
            ],
        ];

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
                    'status' => $subProduct->getStatus() == 1 ? 'published' : 'draft',
                    'created_at' => $this->helper->formatDate($subProduct->getCreatedAt()),
                    'modified_at' => $this->helper->formatDate($subProduct->getUpdatedAt()),
                    'stock' => $this->getSalableQty->execute($subProduct->getSku(), $stockId),
                    'in_stock' => $this->isSkuSalable($subProduct->getSku(), $stockId),
                    'price' => $subProduct->getPrice(),
                    'platform_data' => [
                        'sku' => $subProduct->getSku(),
                    ],
                ];

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
        $endpoint = static::API_ENDPOINT_PRODUCTS;

        if ($isUpdate) {
            $endpoint .= '/' . $feraId;
            $this->apiClient->put($endpoint, $data, $storeId);
            $this->helper->debug('Successfully updated product ' . $data['external_id'] . ' in Fera API');
            return (string) $feraId;
        }

        $response = $this->apiClient->post($endpoint, $data, $storeId);

        $createdId = $response['id'] ?? null;
        if (!is_string($createdId) || $createdId === '') {
            throw new RuntimeException(sprintf(
                'Fera API create response missing id for product %s: %s',
                (string) $data['external_id'],
                $this->helper->jsonEncode($response)
            ));
        }

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
}
