<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Catalog\Model\Product as Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\DataObjectFactory;
use Magento\Framework\Exception\RuntimeException;
use UnexpectedValueException;

class ProductExporter
{
    private const API_ENDPOINT_PRODUCTS = 'v3/private/products';

    /** @var FeraHelper */
    private $helper;
    /** @var StockStateInterface */
    private $stockState;
    /** @var CurlFactory */
    private $curlFactory;
    /** @var EventManager */
    private $eventManager;
    /** @var DataObjectFactory */
    private $dataObjectFactory;

    public function __construct(
        FeraHelper $helper,
        StockStateInterface $stockState,
        CurlFactory $curlFactory,
        EventManager $eventManager,
        DataObjectFactory $dataObjectFactory
    ) {
        $this->helper = $helper;
        $this->stockState = $stockState;
        $this->curlFactory = $curlFactory;
        $this->eventManager = $eventManager;
        $this->dataObjectFactory = $dataObjectFactory;
    }

    /**
     * Push a single product to the Fera API
     */
    public function pushProduct(Product $product): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $productData = $this->buildProductData($product);
        $externalId = (int) $productData['external_id'];
        
        $existingProducts = $this->fetchExistingProducts([$externalId]);
        $isUpdate = !empty($existingProducts);
        
        $this->sendProductData($productData, $isUpdate);
    }

    /**
     * Push multiple products to the Fera API efficiently
     * 
     * @param Product[] $products
     */
    public function pushProducts(array $products): void
    {
        if (!$this->helper->isEnabled() || empty($products)) {
            return;
        }

        $this->validateProductsArray($products);
        
        /** @var array<int, string> $existingProductsCache */
        $existingProductsCache = [];
        $this->loadExistingProducts($products, $existingProductsCache);

        foreach ($products as $product) {
            $productData = $this->buildProductData($product);
            $externalId = (int) $productData['external_id'];
            
            $isUpdate = isset($existingProductsCache[$externalId]);
            
            $this->sendProductData($productData, $isUpdate, $existingProductsCache);
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
            'hidden' => $product->getVisibility() == '1',
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
                /** @var Product $subProduct */
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
     * @param Product[] $products
     * @param array<int, string> $existingProductsCache
     */
    private function loadExistingProducts(array $products, array &$existingProductsCache): void
    {
        $externalIds = [];
        foreach ($products as $product) {
            $externalIds[] = (int) $product->getId();
        }

        $missingIds = array_diff($externalIds, array_keys($existingProductsCache));
        if (empty($missingIds)) {
            return;
        }

        try {
            $existingProducts = $this->fetchExistingProducts($missingIds);
            
            foreach ($existingProducts as $existingProduct) {
                if (isset($existingProduct['external_id'])) {
                    $existingProductsCache[(int) $existingProduct['external_id']] = $existingProduct['id'] ?? null;
                }
            }
        } catch (\Exception $e) {
            $this->helper->log('Error loading existing products from Fera API: ' . $e->getMessage());
        }
    }

    /**
     * @param array{external_id: int|string, ...} $data
     * @param array<int, string> $existingProductsCache
     */
    private function sendProductData(array $data, bool $isUpdate, array &$existingProductsCache = []): void
    {
        $url = $this->helper->getApiUrl() . static::API_ENDPOINT_PRODUCTS;
        
        if ($isUpdate) {
            // For updates, use the Fera ID instead of Magento ID
            $feraId = $existingProductsCache[(int) $data['external_id']] ?? null;
            if (!$feraId) {
                throw new RuntimeException(__(
                    'Cannot update product: Fera ID not found for external_id %1',
                    $data['external_id']
                ));
            }
            $url = $this->helper->getApiUrl() . static::API_ENDPOINT_PRODUCTS . '/' . $feraId;
        }
        
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey());
        
        $jsonData = $this->helper->jsonEncode($data);
        
        if ($isUpdate) {
            $curl->setOption(CURLOPT_CUSTOMREQUEST, 'PUT');
            $curl->post($url, $jsonData);
        } else {
            $curl->post($url, $jsonData);
        }
        
        $httpCode = $curl->getStatus();
        $response = $curl->getBody();
        
        $successCodes = $isUpdate ? [200, 202, 204] : [200, 201];
        if (!in_array($httpCode, $successCodes, true)) {
            $method = $isUpdate ? 'PUT' : 'POST';
            $productId = $data['external_id'] ?? 'unknown';
            
            throw new RuntimeException(__(
                'Failed to %1 product %2 to Fera API. HTTP Status: %3, Response: %4',
                $method,
                $productId,
                $httpCode,
                $response
            ));
        }
        
        if (!$isUpdate && isset($data['external_id'])) {
            // For new products, extract the Fera ID from the response and cache it
            $responseData = json_decode($response, true);
            $feraId = $responseData['data']['id'] ?? null;
            
            if ($feraId) {
                $existingProductsCache[(int) $data['external_id']] = $feraId;
            }
        }
        $successMsg = 'Successfully ' . ($isUpdate ? 'updated' : 'created') .
                     " product {$data['external_id']} in Fera API";
        $this->helper->debug($successMsg);
    }

    /**
     * @param int[] $externalIds
     * @return array<array{id: string, external_id: string}>
     */
    private function fetchExistingProducts(array $externalIds = []): array
    {
        // Increase from default 10 to get more products per request
        $queryParams = ['limit' => 50];
        
        if (!empty($externalIds)) {
            $queryParams['external_ids'] = implode(',', $externalIds);
        }
        
        $url = $this->helper->getApiUrl() . static::API_ENDPOINT_PRODUCTS . '?' . http_build_query($queryParams);
        
        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('SECRET-KEY', $this->helper->getSecretKey());
        
        $curl->get($url);
        
        $response = $curl->getBody();
        $httpCode = $curl->getStatus();
        
        if ($httpCode !== 200) {
            $filterInfo = !empty($externalIds)
                ? sprintf(' (filtered by external_ids: %s)', implode(',', $externalIds))
                : '';
            throw new RuntimeException(__(
                'Failed to fetch existing products from Fera API%1. HTTP Status: %2, Response: %3',
                $filterInfo,
                $httpCode,
                $response
            ));
        }
        
        $decodedResponse = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(__(
                'Invalid JSON response from Fera API: %1',
                json_last_error_msg()
            ));
        }
        
        return $decodedResponse['data'] ?? [];
    }

    /**
     * Legacy method for backward compatibility
     * Send post request to push products
     *
     * @param array<string, mixed> $data Product data to send
     * @deprecated Use sendProductData() instead
     */
    protected function send(array $data): void
    {
        $this->sendProductData($data, false);
    }
}
