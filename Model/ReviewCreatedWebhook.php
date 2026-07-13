<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterfaceFactory as NegativeMessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\NotifyPositiveReview\MessageInterfaceFactory as PositiveMessageInterfaceFactory;
use Fera\Ai\Api\ReviewCreatedWebhookInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Services\ReviewSnapshot\MediaNormalizer;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
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
     * @param NegativeMessageInterfaceFactory $negativeMessageFactory
     * @param PositiveMessageInterfaceFactory $positiveMessageFactory
     * @param MediaNormalizer $mediaNormalizer
     * @param SnapshotBuilder $snapshotBuilder
     * @param SnapshotRepository $snapshotRepository
     */
    public function __construct(
        private Request $request,
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager,
        private PublisherInterface $publisher,
        private FeraWebhookJwtValidator $jwtValidator,
        private NegativeMessageInterfaceFactory $negativeMessageFactory,
        private PositiveMessageInterfaceFactory $positiveMessageFactory,
        private MediaNormalizer $mediaNormalizer,
        private SnapshotBuilder $snapshotBuilder,
        private SnapshotRepository $snapshotRepository
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

        $snapshot = $this->snapshotBuilder->build($payload);
        $this->snapshotRepository->save($snapshot);

        $reviewId = $snapshot['review_id'];
        $rating = $snapshot['rating'];
        $media = $this->mediaNormalizer->normalize($payload['media'] ?? []);
        $mediaJson = $this->encodeMedia($media);

        if ($this->areNegativeReviewNotificationsEnabled($storeId)) {
            $threshold = $this->getRatingThreshold($storeId);
            if ($rating <= $threshold) {
                $message = $this->negativeMessageFactory->create()
                    ->setStoreId($storeId)
                    ->setReviewId($reviewId)
                    ->setRating($rating)
                    ->setFeraStoreId($feraStoreId)
                    ->setExternalOrderId($this->extractString($payload, 'external_order_id'))
                    ->setCustomerName($this->extractNestedString($payload, ['customer', 'name']))
                    ->setCustomerEmail($this->extractNestedString($payload, ['customer', 'email']))
                    ->setReviewTitle($snapshot['heading'])
                    ->setReviewBody($this->normalizeReviewBody($snapshot['body']))
                    ->setProductName($this->extractNestedString($payload, ['product', 'name']))
                    ->setExternalProductId($this->extractString($payload, 'external_product_id'))
                    ->setMediaJson($mediaJson);

                $this->publisher->publish(TopicInterface::NOTIFY_NEGATIVE_REVIEW, $message);
            }
        }

        if (!$this->arePositiveReviewNotificationsEnabled($storeId)) {
            return;
        }

        $positiveThreshold = $this->getPositiveRatingThreshold($storeId);
        if ($rating < $positiveThreshold) {
            return;
        }

        $message = $this->positiveMessageFactory->create()
            ->setStoreId($storeId)
            ->setReviewId($reviewId)
            ->setRating($rating)
            ->setFeraStoreId($feraStoreId)
            ->setExternalOrderId($this->extractString($payload, 'external_order_id'))
            ->setCustomerName($this->extractNestedString($payload, ['customer', 'name']))
            ->setCustomerEmail($this->extractNestedString($payload, ['customer', 'email']))
            ->setReviewTitle($snapshot['heading'])
            ->setReviewBody($this->normalizeReviewBody($snapshot['body']))
            ->setProductName($this->extractNestedString($payload, ['product', 'name']))
            ->setExternalProductId($this->extractString($payload, 'external_product_id'))
            ->setMediaJson($mediaJson);

        $this->publisher->publish(TopicInterface::NOTIFY_POSITIVE_REVIEW, $message);
    }

    /**
     * Check whether negative-review notifications are enabled for store.
     *
     * @param int $storeId
     * @return bool
     */
    private function areNegativeReviewNotificationsEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    private function arePositiveReviewNotificationsEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_ENABLED,
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

    private function getPositiveRatingThreshold(int $storeId): float
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_RATING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!is_numeric($value)) {
            throw new RuntimeException('Positive rating threshold is not set for store ID ' . $storeId);
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

    /**
     * @param list<array{id: string, url: string}> $media
     */
    private function encodeMedia(array $media): string
    {
        $mediaJson = json_encode($media, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($mediaJson)) {
            throw new RuntimeException('Unable to encode review media');
        }

        return $mediaJson;
    }
}
