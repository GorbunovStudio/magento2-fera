<?php

namespace Fera\Ai\Services;

use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ProductExportManager;
use Fera\Ai\Services\ApiClient;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product as Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Event\ManagerInterface as EventManager;
use RuntimeException;
use UnexpectedValueException;

class ProductExporter
{
    protected const API_ENDPOINT_PRODUCTS = 'v3/private/products';

    private FeraHelper $helper;
    private StockStateInterface $stockState;
    private EventManager $eventManager;
    private DataObjectFactory $dataObjectFactory;
    private ProductRepositoryInterface $productRepository;
    private ProductExportManager $productExportManager;
    private ApiClient $apiClient;

    public function __construct(
        FeraHelper $helper,
        StockStateInterface $stockState,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory,
        ProductRepositoryInterface $productRepository,
        ProductExportManager $productExportManager,
        ApiClient $apiClient
    ) {
        $this->helper = $helper;
        $this->stockState = $stockState;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->productRepository = $productRepository;
        $this->productExportManager = $productExportManager;
        $this->apiClient = $apiClient;
    }

    /**
     * Push a single product to the Fera API
     */
    public function pushProduct(Product $product, int $storeId = null): void
    {
        $this->pushProducts([$product], $storeId);
    }

    /**
     * Push multiple products to the Fera API efficiently
     *
     * @param Product[] $products
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
     * @param \Magento\Catalog\Model\Product $product
     * @return mixed[]
     * @phpstan-return array{
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
     *     variants: array,
     *     platform_data: array{sku: string, type: string, regular_price: float}
     * }
     */
    private function buildProductData(Product $product): array
    {
        $thumb = $this->helper->getProductThumbnailUrl($product);

        $productData = [
            'id' => $product->getId(),
            'external_id' => $product->getId(),
            'name' => $product->getName(),
            'price' => $product->getFinalPrice(),
            'status' => $product->getStatus() == 1 ? 'published' : 'draft',
            'created_at' => $this->helper->formatDate($product->getCreatedAt()),
            'modified_at' => $this->helper->formatDate($product->getUpdatedAt()),
            'stock' => $this->stockState->getStockQty($product->getId(), $product->getStore()->getWebsiteId()),
            'in_stock' => $product->isInStock(),
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
                    $type = is_object($subProduct) ? get_class($subProduct) : gettype($subProduct);
                    throw new UnexpectedValueException(
                        'Incorrect type for Product: expected ' . Product::class . ', got ' . $type
                    );
                }

                $variant = [
                    'id' => $subProduct->getId(),
                    'name' => $subProduct->getName(),
                    'status' => $subProduct->getStatus() == 1 ? 'published' : 'draft',
                    'created_at' => $this->helper->formatDate($subProduct->getCreatedAt()),
                    'modified_at' => $this->helper->formatDate($subProduct->getUpdatedAt()),
                    'stock' => $this->stockState->getStockQty(
                        $subProduct->getId(),
                        $product->getStore()->getWebsiteId()
                    ),
                    'in_stock' => (bool) $subProduct->getData('is_in_stock'),
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

        $payload = $this->dataObjectFactory->create(['data' => $productData]);
        $this->eventManager->dispatch('fera_export_product_data_ready', [
            'product' => $product,
            'productData' => $payload,
        ]);

        return $payload->getData();
    }

    /**
     * @param mixed[] $products
     * @phpstan-assert Product[] $products
     */
    private function validateProductsArray(array $products): void
    {
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                $type = is_object($product) ? get_class($product) : gettype($product);
                throw new UnexpectedValueException(
                    'Incorrect type for Product: expected ' . Product::class . ', got ' . $type
                );
            }
        }
    }

    /**
     * @param array{external_id: int|string, ...} $data
     * @param string|null $feraId
     * @param int|null $storeId
     * @return string Fera ID
     * @throws FeraApiException
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
}
