<?php

declare(strict_types=1);

namespace Fera\Ai\Model;

use Fera\Ai\Api\Data\Queue\NotifyReviewUpdate\MessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Api\ReviewUpdatedWebhookInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Services\FeraWebhookJwtValidator;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotComparator;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;
use Throwable;

class ReviewUpdatedWebhook implements ReviewUpdatedWebhookInterface
{
    private const REVIEW_BODY_MAX_LENGTH = 200;
    private const PENDING_UPDATE_STATE = 'pending_update';

    public function __construct(
        private Request $request,
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager,
        private PublisherInterface $publisher,
        private FeraWebhookJwtValidator $jwtValidator,
        private MessageInterfaceFactory $messageFactory,
        private SnapshotBuilder $snapshotBuilder,
        private SnapshotRepository $snapshotRepository,
        private SnapshotComparator $snapshotComparator
    ) {
    }

    public function execute(): void
    {
        $storeId = (int) $this->storeManager->getStore()->getId();
        $jwt = $this->request->getParam('jwt');
        if (!is_string($jwt) || $jwt === '') {
            throw new WebapiException(new Phrase('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        try {
            $claims = $this->jwtValidator->validateToken($jwt, $storeId, 'review_update');
        } catch (Throwable) {
            throw new WebapiException(new Phrase('Unauthorized'), 0, WebapiException::HTTP_UNAUTHORIZED);
        }

        $feraStoreId = $this->resolveFeraStoreId($claims);

        $payload = $this->request->getBodyParams();
        if (!is_array($payload)) {
            throw new WebapiException(new Phrase('Request body must be a JSON object'), 0, WebapiException::HTTP_BAD_REQUEST);
        }

        $currentSnapshot = $this->snapshotBuilder->build($payload);
        $previousSnapshot = $this->snapshotRepository->getByReviewId($currentSnapshot['review_id']);
        $changedFields = $previousSnapshot === null
            ? []
            : $this->snapshotComparator->compare($previousSnapshot, $currentSnapshot);

        if (
            $previousSnapshot === null
            || $changedFields === []
            || !$this->isPendingUpdate($payload)
            || !$this->isEnabled($storeId)
        ) {
            $this->snapshotRepository->save($currentSnapshot);
            return;
        }

        $changedFieldsJson = json_encode($changedFields, JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($changedFieldsJson)) {
            throw new RuntimeException('Unable to encode changed review fields');
        }

        $message = $this->messageFactory->create()
            ->setStoreId($storeId)
            ->setReviewId($currentSnapshot['review_id'])
            ->setRating($currentSnapshot['rating'])
            ->setFeraStoreId($feraStoreId)
            ->setExternalOrderId($this->extractString($payload, 'external_order_id'))
            ->setCustomerName($this->extractNestedString($payload, ['customer', 'name']))
            ->setCustomerEmail($this->extractNestedString($payload, ['customer', 'email']))
            ->setReviewTitle($currentSnapshot['heading'])
            ->setReviewBody($this->normalizeReviewBody($currentSnapshot['body']))
            ->setProductName($this->extractNestedString($payload, ['product', 'name']))
            ->setExternalProductId($this->extractString($payload, 'external_product_id'))
            ->setChangedFieldsJson($changedFieldsJson);

        $this->publisher->publish(TopicInterface::NOTIFY_REVIEW_UPDATE, $message);
        $this->snapshotRepository->save($currentSnapshot);
    }

    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isPendingUpdate(array $payload): bool
    {
        return ($payload['state'] ?? null) === self::PENDING_UPDATE_STATE;
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
}
