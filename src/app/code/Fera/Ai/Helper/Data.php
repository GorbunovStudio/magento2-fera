<?php
/**
 * @author: Sviatoslav Lashkiv
 * @email: ss.lashkiv@gmail.com
 * @team: MageCloud
 */

namespace Fera\Ai\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Module\ResourceInterface as ModuleResourceInterface;
use Magento\Framework\Json\Helper\Data as JsonHelper;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Catalog\Block\Product\ImageBuilder;
use Magento\Sales\Model\Order\Item;
use Fera\Ai\Logger\Logger;

class Data extends AbstractHelper
{
    const FORMAT_DATE = 'Y-m-d\TH:i:sP';

    /**
     * @var ModuleResourceInterface
     */
    private $moduleResource;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;
    /**
     * @var JsonHelper
     */
    private $jsonHelper;
    /**
     * @var CheckoutSession
     */
    private $checkoutSession;
    /**
     * @var DateTimeFactory
     */
    private $dateTime;
    /**
     * @var ImageBuilder
     */
    private $imageBuilder;
    /**
     * @var Logger
     */
    private $logger;

    public function __construct(
        Context $context,
        ModuleResourceInterface $moduleResource,
        StoreManagerInterface $storeManager,
        JsonHelper $jsonHelper,
        CheckoutSession $checkoutSession,
        DateTimeFactory $dateTime,
        ImageBuilder $imageBuilder,
        Logger $logger
    ) {
        $this->moduleResource = $moduleResource;
        $this->storeManager = $storeManager;
        $this->jsonHelper = $jsonHelper;
        $this->checkoutSession = $checkoutSession;
        $this->dateTime = $dateTime;
        $this->imageBuilder = $imageBuilder;
        $this->logger = $logger;

        parent::__construct($context);
    }

    /**
     * Write to the Fera.ai log file
     *
     * @param  mixed $msg message to log
     * @return $this
     */
    public function log($msg)
    {
        $this->logger->info($msg);
        return $this;
    }

    /**
     * Write to the debug output ONLY if the debug mode is enabled
     *
     * @param  mixed $msg Message to log
     * @return $this
     */
    public function debug($msg)
    {
        if ($this->isDebugMode()) {
            return $this->log($msg);
        }

        return $this;
    }

    /**
     * @return String Version of the extension (x.x.x)
     */
    public function getVersion()
    {
        return $this->moduleResource->getDbVersion('Fera_Ai');
    }

    /**
     * Fera Ai public key either from the store config or the environment files
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getPublicKey($storeId = null)
    {
        return $this->scopeConfig->getValue(
            'fera_ai/fera_ai_group/public_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Fera Ai secret (private) key, either from the environment fiels or the store config
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getSecretKey($storeId = null)
    {
        return $this->scopeConfig->getValue(
            'fera_ai/fera_ai_group/secret_key',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isEnabled($storeId = null)
    {
        if (!$this->isConfigured($storeId)) {
            return false;
        }

        return $this->scopeConfig->getValue(
            'fera_ai/general/enabled',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * True if the current Fera Ai configuration is setup to work properly
     *
     * @param  int|null $storeId
     * @return boolean false if it is not ready for use
     */
    public function isConfigured($storeId = null)
    {
        $publicKey = $this->getPublicKey($storeId);
        $secretKey = $this->getSecretKey($storeId);
        $appUrl = $this->getAppUrl($storeId);
        $apiUrl = $this->getApiUrl($storeId);
        $jsUrl = $this->getJsUrl($storeId);

        return !empty($publicKey) && !empty($secretKey) && !empty($appUrl) && !empty($apiUrl) && !empty($jsUrl);
    }

    /**
     * The URL path to the APP (https). For example: https://app.fera.ai
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getAppUrl($storeId = null)
    {
        return $this->scopeConfig->getValue(
            'fera_ai/fera_ai_group/app_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The URL path to the API (https). For example: https://api.fera.ai/api/v1
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getApiUrl($storeId = null)
    {
        return $this->scopeConfig->getValue(
            'fera_ai/fera_ai_group/api_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The URL to the javascript file on the Fera CDN. For example: https://cdn.fera.ai/js/bananastand.js
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getJsUrl($storeId = null)
    {
        return $this->scopeConfig->getValue(
            'fera_ai/fera_ai_group/js_url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Is debug mode enabled? If so we will output much more extra info to the logs to help developers.
     *
     * @return boolean
     */
    public function isDebugMode()
    {
        return $this->scopeConfig->isSetFlag(
            'fera_ai/general/debug_mode',
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @param \Magento\Sales\Model\Order\Item[]|\Magento\Quote\Model\Quote\Item[] $items
     * @return array<int,array{product_id:int,price:float,total:float,name:string,quantity:int,variant_id?:int}>
     */
    public function serializeQuoteItems($items): array
    {
        $parentTypeMap = [];
        $itemMap = [];
        $childItems = [];

        foreach ($items as $cartItem) {
            if ($cartItem->getParentItemId()) {
                $childItems[] = $cartItem;
                continue;
            }

            $parentId = $cartItem->getId();
            $parentType = $cartItem->getProductType();
            $parentTypeMap[$parentId] = $parentType;
            $data = $this->buildItemData($cartItem);
            if ($data !== null) {
                $itemMap[$parentId] = $data;
            }
        }

        foreach ($childItems as $cartItem) {
            $parentId = $cartItem->getParentItemId();
            $parentType = isset($parentTypeMap[$parentId]) ? $parentTypeMap[$parentId] : null;

            if ($parentType === 'configurable') {
                if ($this->getRemainingQuantity($cartItem) <= 0) {
                    unset($itemMap[$parentId]);
                } elseif (isset($itemMap[$parentId])) {
                    $itemMap[$parentId]['name'] = $cartItem->getName() ?? '';
                    $itemMap[$parentId]['variant_id'] = (int) $cartItem->getProductId();
                }
                continue;
            }

            if ($parentType === 'bundle') {
                continue;
            }
            $data = $this->buildItemData($cartItem);
            if ($data !== null) {
                $itemMap[$cartItem->getId()] = $data;
            }
        }

        return array_values($itemMap);
    }

    /**
     * @param \Magento\Sales\Model\Order\Item|\Magento\Quote\Model\Quote\Item $item
     */
    private function getRemainingQuantity($item): int
    {
        if ($item instanceof Item) {
            $qtyOrdered = (float) $item->getQtyOrdered();
            $qtyRefunded = (float) $item->getQtyRefunded();
            $qtyCanceled = (float) $item->getQtyCanceled();
            $left = (int) max(0, (int) round($qtyOrdered) - (int) round($qtyRefunded) - (int) round($qtyCanceled));
            return $left;
        }
        
        $qty = (float) $item->getQty();
        return (int) max(0, (int) round($qty));
    }

    /**
     * @param \Magento\Sales\Model\Order\Item|\Magento\Quote\Model\Quote\Item $item
     * @return array{product_id:int,price:float,total:float,name:string,quantity:int}|null
     */
    private function buildItemData($item): ?array
    {
        $qty = $this->getRemainingQuantity($item);
        if ($qty <= 0) {
            return null;
        }

        $rowTotal = (float) ($item->getRowTotal() ?? 0.0);
        if ($item instanceof Item) {
            $qtyOrdered = (float) $item->getQtyOrdered();
            if ($qtyOrdered > 0 && $qty < (int) round($qtyOrdered)) {
                $ratio = $qty / $qtyOrdered;
                $rowTotal = $rowTotal * $ratio;
            }
        }

        return [
            'product_id' => (int) $item->getProductId(),
            'price' => $item->getRefPrice() ?? 0.0,
            'total' => $rowTotal,
            'name' => $item->getName() ?? '',
            'quantity' => $qty,
        ];
    }

    /**
     * @return string - The contents of the cart as a json string.
     */
    public function getCartJson()
    {
        $quote = $this->checkoutSession->getQuote();

        $data = [
            'currency' => $this->storeManager->getStore()->getCurrentCurrency()->getCode(),
            'total' => $quote->getSubtotal(),
            'grand_total' => $quote->getGrandTotal()
        ];

        $data['items'] = $this->serializeQuoteItems($quote->getAllVisibleItems());

        return $this->jsonEncode($data);
    }

    public function getDebugJs()
    {
        if ($this->isDebugMode()) {
            return "window.feraDebugMode = true;";
        }

        return "";
    }

    public function jsonEncode($data)
    {
        return $this->jsonHelper->jsonEncode($data);
    }

    public function formatDate($date)
    {
        return $this->dateTime->create($date)->format(self::FORMAT_DATE);
    }

    public function getImage($product, $imageId, $attributes = [])
    {
        return $this->imageBuilder->setProduct($product)
            ->setImageId($imageId)
            ->setAttributes($attributes)
            ->create();
    }

    public function getProductThumbnailUrl($product)
    {
        $imageType = 'product_thumbnail_image';
        $image = $this->getImage($product, $imageType);

        return $image->getImageUrl();
    }
}
