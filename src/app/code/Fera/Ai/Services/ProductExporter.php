<?php

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data as FeraHelper;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\HTTP\Client\Curl as Curl;
use Magento\Catalog\Model\Product as Product;
use Magento\Framework\Event\ManagerInterface as EventManager;

class ProductExporter
{
    public function __construct(
        private FeraHelper $helper,
        private StockStateInterface $stockState,
        private Curl $curl,
        private EventManager $eventManager
    ) {
    }

    /**
     * Push a single product to the Fera API
     */
    public function pushProduct(Product $product)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

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
            $cfgAttr = $product->getTypeInstance()->getConfigurableAttributesAsArray($product);

            foreach ($product->getTypeInstance()->getUsedProducts($product) as $subProduct) {
                $variant = [
                    'id' => $subProduct->getId(),
                    'name' => $subProduct->getName(),
                    'status' => $subProduct->getStatus() == 1 ? 'published' : 'draft',
                    'created_at' => $this->helper->formatDate($subProduct->getCreatedAt()),
                    'modified_at' => $this->helper->formatDate($subProduct->getUpdatedAt()),
                    'stock' => $this->stockState->getStockQty($subProduct->getId(), $subProduct->getStore()->getWebsiteId()),
                    'in_stock' => $subProduct->isInStock(),
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

        $this->eventManager->dispatch('fera_export_product_data_ready', [
            'product' => $product,
            'productData' => $productData,
        ]);

        $this->send($productData);
    }

    /**
     * Send post request to push products
     */
    protected function send(array $data)
    {
        $url = $this->helper->getApiUrl() . 'v3/private/products.json';
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('SECRET-KEY', $this->helper->getSecretKey());
        $this->curl->post($url, $this->helper->jsonEncode($data));

        // Intentionally ignore body; side-effect only
        $this->curl->getBody();
    }
}
