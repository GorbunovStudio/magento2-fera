<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterfaceFactory;
use Fera\Ai\Api\ReviewCreatedWebhookInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Services\FeraWebhookJwtValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;

class ReviewCreatedWebhook implements ReviewCreatedWebhookInterface
{
    private const TOPIC_NOTIFY_NEGATIVE_REVIEW = 'fera.review.notify_negative';

    public function __construct(
        private Request $request,
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager,
        private PublisherInterface $publisher,
        private FeraWebhookJwtValidator $jwtValidator,
        private MessageInterfaceFactory $messageFactory
    ) {
    }

    public function execute(): void
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->isEnabled($storeId)) {
            return;
        }

        $jwt = $this->request->getParam('jwt');
        if (!is_string($jwt) || $jwt === '') {
            throw new RuntimeException('Forbidden');
        }

        $claims = $this->jwtValidator->validateToken($jwt, $storeId, 'review_create');
        $feraStoreId = $this->resolveFeraStoreId($claims);

        $payload = $this->request->getBodyParams();
        if (!is_array($payload)) {
            throw new RuntimeException('Request body must be a JSON object');
        }

        $reviewId = $this->resolveReviewId($payload);
        $rating = $this->resolveRating($payload);

        $threshold = $this->getRatingThreshold($storeId);
        if ($rating > $threshold) {
            return;
        }

        $message = $this->messageFactory->create()
            ->setStoreId($storeId)
            ->setReviewId($reviewId)
            ->setRating($rating)
            ->setFeraStoreId($feraStoreId)
            ->setExternalOrderId($this->extractString($payload, 'external_order_id'))
            ->setCustomerName($this->extractNestedString($payload, ['customer', 'name']))
            ->setHeading($this->extractString($payload, 'heading'))
            ->setBody($this->extractString($payload, 'body'))
            ->setProductName($this->extractNestedString($payload, ['product', 'name']));

        $this->publisher->publish(self::TOPIC_NOTIFY_NEGATIVE_REVIEW, $message);
    }

    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function getRatingThreshold(int $storeId): float
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_RATING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!is_numeric($value)) {
            throw new RuntimeException('Rating threshold is not set for store ID ' . $storeId);
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function resolveFeraStoreId(array $claims): string
    {
        $value = $claims['store_id'] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        throw new RuntimeException('Forbidden');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveReviewId(array $payload): string
    {
        $reviewId = $payload['id'] ?? null;
        if (!is_string($reviewId) || trim($reviewId) === '') {
            throw new RuntimeException('Field "id" must be a non-empty string');
        }

        return trim($reviewId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveRating(array $payload): float
    {
        $rating = $payload['rating'] ?? null;
        if (!is_numeric($rating)) {
            throw new RuntimeException('Field "rating" must be numeric');
        }

        return (float) $rating;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;
        if (!is_string($value)) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $path
     */
    private function extractNestedString(array $payload, array $path): string
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_string($current) ? $current : '';
    }
}
