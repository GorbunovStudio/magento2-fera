<?php

declare(strict_types=1);

/**
 * @author: Sviatoslav Lashkiv
 * @email: ss.lashkiv@gmail.com
 * @team: MageCloud
 */

namespace Fera\Ai\Helper;

use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Logger\Logger;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Block\Product\Image;
use Magento\Catalog\Block\Product\ImageFactory;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Intl\DateTimeFactory;
use Magento\Framework\Module\ResourceInterface as ModuleResourceInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;
use Stringable;

class Data extends AbstractHelper
{
    public const FORMAT_DATE = 'Y-m-d\TH:i:sP';

    public function __construct(
        Context $context,
        private ModuleResourceInterface $moduleResource,
        private Json $json,
        private DateTimeFactory $dateTime,
        private ImageFactory $imageFactory,
        private Logger $logger
    ) {
        parent::__construct($context);
    }

    /**
     * Write to the Fera.ai log file
     *
     * @param  string|Stringable $msg message to log
     * @return $this
     */
    public function log(string|Stringable $msg): static
    {
        $this->logger->info($msg);
        return $this;
    }

    /**
     * Write to the debug output ONLY if the debug mode is enabled
     *
     * @param  string|Stringable $msg Message to log
     * @return $this
     */
    public function debug(string|Stringable $msg): static
    {
        if ($this->isDebugMode()) {
            return $this->log($msg);
        }

        return $this;
    }

    /**
     * @return string|false Version of the extension (x.x.x)
     */
    public function getVersion(): string|false
    {
        return $this->moduleResource->getDbVersion('Fera_Ai');
    }

    /**
     * Fera Ai public key either from the store config or the environment files
     *
     * @param  int|null $storeId
     * @return string|null
     */
    public function getPublicKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::PUBLIC_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        return is_string($value) ? $value : null;
    }

    /**
     * Fera Ai secret (private) key, either from the environment fields or the store config
     *
     * @param  int|null $storeId
     * @return string|null
     */
    public function getSecretKey(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::SECRET_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_string($value) ? $value : null;
    }

    public function isEnabled(?int $storeId = null): bool
    {
        if (!$this->isConfigured($storeId)) {
            return false;
        }

        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return (bool) $value;
    }

    public function shouldExportOrderOnCreation(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            ConfigOptionInterface::EXPORT_ORDER_ON_CREATION,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isMinimizeDataSharingEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->getValue(
            ConfigOptionInterface::MINIMIZE_DATA_SHARING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getFulfillmentExportDelayDays(?int $storeId = null): int
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::FULFILLMENT_EXPORT_DELAY_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        
        if (!is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

    /**
     * True if the current Fera Ai configuration is setup to work properly
     *
     * @param  int|null $storeId
     * @return boolean false if it is not ready for use
     */
    public function isConfigured(?int $storeId = null)
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
    public function getAppUrl(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::APP_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_string($value) ? $value : null;
    }

    /**
     * The URL path to the API (https). For example: https://api.fera.ai/api/v1
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getApiUrl(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::API_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_string($value) ? $value : null;
    }

    /**
     * The URL to the javascript file on the Fera CDN. For example: https://cdn.fera.ai/js/bananastand.js
     *
     * @param  int|null $storeId
     * @return string
     */
    public function getJsUrl(?int $storeId = null): ?string
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::JS_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return is_string($value) ? $value : null;
    }

    /**
     * Is debug mode enabled? If so we will output much more extra info to the logs to help developers.
     *
     * @return boolean
     */
    public function isDebugMode(): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::DEBUG_MODE,
            ScopeInterface::SCOPE_STORE
        );
    }

    public function getDebugJs(): string
    {
        if ($this->isDebugMode()) {
            return "window.feraDebugMode = true;";
        }

        return "";
    }

    /**
     * @param mixed $data
     * @return string
     */
    public function jsonEncode($data): string
    {
        // @phpstan-ignore argument.type
        $result = $this->json->serialize($data);
        if (!is_string($result)) {
            throw new \InvalidArgumentException('Unable to serialize value.');
        }

        return $result;
    }

    public function formatDate(string $date): string
    {
        return $this->dateTime->create($date)->format(self::FORMAT_DATE);
    }

    /**
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param string $imageId
     * @param mixed[] $attributes
     * @return \Magento\Catalog\Block\Product\Image
     */
    public function getImage(ProductInterface $product, string $imageId, array $attributes = []): Image
    {
        if (!$product instanceof Product) {
            throw new \UnexpectedValueException(
                'Expected instance of ' . Product::class . ', got ' . get_debug_type($product)
            );
        }

        return $this->imageFactory->create($product, $imageId, $attributes);
    }

    public function getProductThumbnailUrl(ProductInterface $product): string
    {
        $imageType = 'product_thumbnail_image';
        $image = $this->getImage($product, $imageType);

        return $image->getImageUrl();
    }
}
