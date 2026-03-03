<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterfaceFactory;
use Fera\Ai\Api\ReviewCreatedWebhookInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Services\FeraWebhookJwtValidator;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;
use Throwable;

class ReviewCreatedWebhook implements ReviewCreatedWebhookInterface
{
    private const REVIEW_BODY_MAX_LENGTH = 200;

    /**
     * @param Request $request
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param PublisherInterface $publisher
     * @param FeraWebhookJwtValidator $jwtValidator
     * @param MessageInterfaceFactory $messageFactory
     */
    public function __construct(
        private Request $request,
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager,
        private PublisherInterface $publisher,
        private FeraWebhookJwtValidator $jwtValidator,
        private MessageInterfaceFactory $messageFactory
    ) {
    }

    /**
     * Process incoming review-created webhook request.
     *
     * @return void
     */
    public function execute(): void
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->isEnabled($storeId)) {
            return;
        }

        $jwt = $this->request->getParam('jwt');
        if (!is_string($jwt) || $jwt === '') {
            throw new WebapiException(new Phrase('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        try {
            $claims = $this->jwtValidator->validateToken($jwt, $storeId, 'review_create');
        } catch (Throwable) {
            throw new WebapiException(new Phrase('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        $feraStoreId = $this->resolveFeraStoreId($claims);

        $payload = $this->request->getBodyParams();
        if (!is_array($payload)) {
            throw new WebapiException(new Phrase('Request body must be a JSON object'), 0, WebapiException::HTTP_BAD_REQUEST);
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
            ->setReviewTitle($this->extractString($payload, 'heading'))
            ->setReviewBody($this->normalizeReviewBody($this->extractString($payload, 'body')))
            ->setProductName($this->extractNestedString($payload, ['product', 'name']));

        $this->publisher->publish(TopicInterface::NOTIFY_NEGATIVE_REVIEW, $message);
    }

    /**
     * Check whether review notifications are enabled for store.
     *
     * @param int $storeId
     * @return bool
     */
    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Resolve configured rating threshold for store.
     *
     * @param int $storeId
     * @return float
     */
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

        throw new WebapiException(new Phrase('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveReviewId(array $payload): string
    {
        $reviewId = $payload['id'] ?? null;
        if (!is_string($reviewId) || trim($reviewId) === '') {
            throw new WebapiException(new Phrase('Field "id" must be a non-empty string'), 0, WebapiException::HTTP_BAD_REQUEST);
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
            throw new WebapiException(new Phrase('Field "rating" must be numeric'), 0, WebapiException::HTTP_BAD_REQUEST);
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

    private function normalizeReviewBody(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= self::REVIEW_BODY_MAX_LENGTH) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::REVIEW_BODY_MAX_LENGTH));
    }
}
