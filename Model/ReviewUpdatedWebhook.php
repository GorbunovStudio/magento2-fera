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
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;
use Throwable;

/**
 * @phpstan-import-type ReviewSnapshot from SnapshotBuilder
 */
class ReviewUpdatedWebhook implements ReviewUpdatedWebhookInterface
{
    private const HTTP_SERVICE_UNAVAILABLE = 503;
    private const LOCK_WAIT_TIMEOUT_SECONDS = 10;

    public function __construct(
        private Request $request,
        private ScopeConfigInterface $scopeConfig,
        private StoreManagerInterface $storeManager,
        private PublisherInterface $publisher,
        private FeraWebhookJwtValidator $jwtValidator,
        private MessageInterfaceFactory $messageFactory,
        private SnapshotBuilder $snapshotBuilder,
        private SnapshotRepository $snapshotRepository,
        private SnapshotComparator $snapshotComparator,
        private LockManagerInterface $lockManager,
        private StoreGroupService $storeGroupService
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

        $canonicalStoreId = $this->storeGroupService->getCanonicalStoreId($storeId);
        try {
            $currentSnapshot = $this->snapshotBuilder->build(
                $payload,
                $canonicalStoreId
            );
        } catch (InvalidArgumentException $exception) {
            throw new WebapiException(
                new Phrase($exception->getMessage()),
                0,
                WebapiException::HTTP_BAD_REQUEST
            );
        }
        $lockName = $this->buildLockName($storeId, $currentSnapshot['review_id']);
        if (!$this->lockManager->lock($lockName, self::LOCK_WAIT_TIMEOUT_SECONDS)) {
            throw new WebapiException(
                new Phrase('Review update processing is busy'),
                0,
                self::HTTP_SERVICE_UNAVAILABLE
            );
        }

        try {
            $this->processReviewUpdate($storeId, $feraStoreId, $payload, $currentSnapshot);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    /**
     * @param int $storeId
     * @param string $feraStoreId
     * @param mixed[] $payload
     * @phpstan-param array<string, mixed> $payload
     * @param mixed[] $currentSnapshot
     * @phpstan-param ReviewSnapshot $currentSnapshot
     * @return void
     * @throws \RuntimeException
     */
    private function processReviewUpdate(
        int $storeId,
        string $feraStoreId,
        array $payload,
        array $currentSnapshot
    ): void {
        $previousSnapshot = $this->snapshotRepository->getByReviewId($currentSnapshot['review_id']);
        $changedFields = $previousSnapshot === null
            ? []
            : $this->snapshotComparator->compare($previousSnapshot, $currentSnapshot);

        $this->snapshotRepository->save($currentSnapshot);

        if ($previousSnapshot === null
            || $changedFields === []
            || !$this->areReviewUpdateNotificationsEnabled($storeId)
        ) {
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
            ->setReviewBody($currentSnapshot['body'])
            ->setProductName($this->extractNestedString($payload, ['product', 'name']))
            ->setExternalProductId($this->extractString($payload, 'external_product_id'))
            ->setChangedFieldsJson($changedFieldsJson);

        $this->publisher->publish(TopicInterface::NOTIFY_REVIEW_UPDATE, $message);
    }

    private function buildLockName(int $storeId, string $reviewId): string
    {
        return 'fera_review_updated_' . $storeId . '_' . hash('sha256', $reviewId);
    }

    private function areReviewUpdateNotificationsEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::REVIEW_UPDATE_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
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
}
