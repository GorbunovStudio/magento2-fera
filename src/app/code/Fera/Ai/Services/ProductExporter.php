<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\ProductExportManager;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Catalog\Model\Product as Product;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\DataObjectFactory;
use Magento\Catalog\Model\Product\Visibility;
use RuntimeException;
use UnexpectedValueException;

class ProductExporter
{
    private const API_ENDPOINT_PRODUCTS = 'v3/private/products';

    private FeraHelper $helper;
    private StockStateInterface $stockState;
    private CurlFactory $curlFactory;
    private EventManager $eventManager;
    private DataObjectFactory $dataObjectFactory;
    private ProductRepositoryInterface $productRepository;
    private ProductExportManager $productExportManager;

    public function __construct(
        FeraHelper $helper,
        StockStateInterface $stockState,
        CurlFactory $curlFactory,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory,
        ProductRepositoryInterface $productRepository,
        ProductExportManager $productExportManager
    ) {
        $this->helper = $helper;
        $this->stockState = $stockState;
        $this->curlFactory = $curlFactory;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->productRepository = $productRepository;
        $this->productExportManager = $productExportManager;
    }

    /**
     * Push a single product to the Fera API
     */
    public function pushProduct(Product $product, $storeId = null): void
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
            
            $productData = $this->buildProductData($product, $storeId);
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
     * @return array{
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
     *     tags: array,
     *     variants: array,
     *     platform_data: array{sku: string, type: string, regular_price: float}
     * }
     */
    private function buildProductData(Product $product, $storeId = null): array
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
     * @param array $products
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
     * @param int|null $storeId
     * @return string Fera ID
     */
    private function sendProductData(array $data, ?string $feraId, $storeId = null): string
    {
        $url = $this->helper->getApiUrl($storeId) . static::API_ENDPOINT_PRODUCTS;
        $isUpdate = $feraId !== null && $feraId !== '';
        if ($isUpdate) {
            $url .= '/' . $feraId;
        }
        
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey($storeId));
        
        $jsonData = $this->helper->jsonEncode($data);
        
        if ($isUpdate) {
            $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
        }
        $curl->post($url, $jsonData);
        
        $httpCode = $curl->getStatus();
        $response = $curl->getBody();
        
        $successCodes = $isUpdate ? [200, 202, 204] : [200, 201];
        if (!in_array($httpCode, $successCodes, true)) {
            $method = $isUpdate ? 'PUT' : 'POST';
            $productId = $data['external_id'] ?? 'unknown';

            throw new RuntimeException(sprintf(
                'Failed to %s product %s to Fera API. HTTP Status: %s, Response: %s',
                $method,
                (string) $productId,
                (string) $httpCode,
                $response
            ));
        }

        if ($isUpdate) {
            $this->helper->debug('Successfully updated product ' . $data['external_id'] . ' in Fera API');
            return (string) $feraId;
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new RuntimeException(sprintf(
                'Invalid JSON response from Fera API on product create: %s',
                json_last_error_msg()
            ));
        }
        $createdId = $decoded['id'] ?? null;
        if (!is_string($createdId) || $createdId === '') {
            throw new RuntimeException(sprintf(
                'Fera API create response missing id for product %s: %s',
                (string)($data['external_id'] ?? 'unknown'),
                $response
            ));
        }

        $this->helper->debug('Successfully created product ' . $data['external_id'] . ' in Fera API');
        return $createdId;
    }

    /**
     * Legacy method for backward compatibility
     * Send post request to push products
     *
     * @param array<string, mixed> $data Product data to send
     * @param int|null $storeId
     * @deprecated Use sendProductData() instead
     */
    protected function send(array $data, $storeId = null): void
    {
        $this->sendProductData($data, null, $storeId);
    }
}
