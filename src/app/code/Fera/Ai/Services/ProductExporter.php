<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Catalog\Model\Product as Product;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\RuntimeException;
use UnexpectedValueException;

class ProductExporter
{
    private const API_ENDPOINT_PRODUCTS = 'v3/private/products';
    
    private array $existingProductsCache = []; // [external_id => ['exists' => bool, 'fera_id' => string|null]]

    public function __construct(
        private FeraHelper $helper,
        private StockStateInterface $stockState,
        private CurlFactory $curlFactory,
        private EventManager $eventManager
    ) {
    }

    /**
     * Push a single product to the Fera API
     */
    public function pushProduct(Product $product): void
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $this->pushProducts([$product]);
    }

    /**
     * Push multiple products to the Fera API efficiently
     */
    public function pushProducts(array $products): void
    {
        if (!$this->helper->isEnabled() || empty($products)) {
            return;
        }

        $this->validateProductsArray($products);
        
        $this->loadExistingProducts($products);

        foreach ($products as $product) {
            $productData = $this->buildProductData($product);
            $externalId = (int) $productData['external_id'];
            
            $isUpdate = $this->isProductExists($externalId);
            
            if ($isUpdate && !$this->getFeraId($externalId)) {
                $isUpdate = false;
            }
            
            $this->sendProductData($productData, $isUpdate);
        }
    }

    /**
     * Build product data array for API call
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

        $payload = new DataObject($productData);
        $this->eventManager->dispatch('fera_export_product_data_ready', [
            'product' => $product,
            'productData' => $payload,
        ]);

        return $payload->getData();
    }

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

    private function loadExistingProducts(array $products): void
    {
        $externalIds = [];
        foreach ($products as $product) {
            $externalIds[] = (int) $product->getId();
        }

        $missingIds = array_diff($externalIds, array_keys($this->existingProductsCache));
        if (empty($missingIds)) {
            return;
        }

        try {
            $existingProducts = $this->fetchExistingProducts($missingIds);
            
            foreach ($existingProducts as $existingProduct) {
                if (isset($existingProduct['external_id'])) {
                    $this->existingProductsCache[(int) $existingProduct['external_id']] = [
                        'exists' => true,
                        'fera_id' => $existingProduct['id'] ?? null
                    ];
                }
            }
            
            foreach ($missingIds as $externalId) {
                if (!isset($this->existingProductsCache[$externalId])) {
                    $this->existingProductsCache[$externalId] = [
                        'exists' => false,
                        'fera_id' => null
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->helper->log('Error loading existing products from Fera API: ' . $e->getMessage());
        }
    }

    private function isProductExists(int $externalId): bool
    {
        $cached = $this->existingProductsCache[$externalId] ?? null;
        return $cached['exists'] ?? false;
    }

    private function getFeraId(int $externalId): ?string
    {
        $cached = $this->existingProductsCache[$externalId] ?? null;
        return $cached['fera_id'] ?? null;
    }

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

    private function sendProductData(array $data, bool $isUpdate): void
    {
        $url = $this->helper->getApiUrl() . static::API_ENDPOINT_PRODUCTS;
        
        if ($isUpdate) {
            // For updates, use the Fera ID instead of Magento ID
            $feraId = $this->getFeraId((int) $data['external_id']);
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
            // For new products, we need to extract the Fera ID from the response
            $responseData = json_decode($response, true);
            $feraId = $responseData['data']['id'] ?? null;
            
            $this->existingProductsCache[(int) $data['external_id']] = [
                'exists' => true,
                'fera_id' => $feraId
            ];
        }
        $successMsg = 'Successfully ' . ($isUpdate ? 'updated' : 'created') .
                     " product {$data['external_id']} in Fera API";
        $this->helper->debug($successMsg);
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
