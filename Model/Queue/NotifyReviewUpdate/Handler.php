<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\NotifyReviewUpdate;

use Fera\Ai\Api\Data\Queue\NotifyReviewUpdate\MessageInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Logger\Logger;
use GuzzleHttp\ClientFactory;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

/**
 * @phpstan-type ChangedFields array<string, array{before: mixed, after: mixed}>
 * @phpstan-type NotificationData array{
 *   store_name: string,
 *   store_code: string,
 *   rating: float,
 *   customer_name: string,
 *   review_title: string,
 *   review_body: string,
 *   product_name: string,
 *   external_order_id: string,
 *   fera_review_url: string,
 *   magento_order_url: string
 * }
 */
class Handler
{
    private const SLACK_CONNECT_TIMEOUT_SECONDS = 2.0;
    private const SLACK_TIMEOUT_SECONDS = 5.0;
    private const MAX_SLACK_FIELD_TEXT_LENGTH = 3000;

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private OrderRepositoryInterface $orderRepository,
        private UrlInterface $backendUrl,
        private ClientFactory $clientFactory,
        private StoreManagerInterface $storeManager,
        private Logger $logger,
        private EventManager $eventManager
    ) {
    }

    public function process(MessageInterface $message): void
    {
        try {
            $this->processMessage($message);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Unable to process review update notification: ' . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'store_id' => $message->getStoreId(),
                    'review_id' => $message->getReviewId(),
                ]
            );

            throw new RuntimeException(
                'Unable to process review update notification: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            if ($this->orderRepository instanceof ResetAfterRequestInterface) {
                $this->orderRepository->_resetState();
            }
        }
    }

    private function processMessage(MessageInterface $message): void
    {
        $storeId = $message->getStoreId();
        if ($storeId <= 0) {
            throw new RuntimeException('Invalid store ID in message');
        }

        if (!$this->areReviewUpdateNotificationsEnabled($storeId)) {
            return;
        }

        $store = $this->storeManager->getStore($storeId);
        $feraReviewUrl = $this->buildFeraReviewUrl($storeId, $message->getFeraStoreId(), $message->getReviewId());
        $orderData = $this->resolveOrderData($message->getExternalOrderId());
        $order = $orderData['order'];
        $orderIncrementId = $orderData['increment_id'] !== ''
            ? $orderData['increment_id']
            : $message->getExternalOrderId();

        $notificationData = [
            'store_name' => $store->getName(),
            'store_code' => $store->getCode(),
            'rating' => $message->getRating(),
            'customer_name' => $this->fallback($message->getCustomerName()),
            'review_title' => $this->fallback($message->getReviewTitle()),
            'review_body' => $this->fallback($message->getReviewBody()),
            'product_name' => $this->fallback($message->getProductName()),
            'external_order_id' => $this->fallback($orderIncrementId),
            'fera_review_url' => $feraReviewUrl,
            'magento_order_url' => $orderData['url'],
        ];

        $slackWebhookUrl = $this->getConfigString(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL,
            $storeId
        );

        if ($slackWebhookUrl === '') {
            return;
        }

        $this->assertSlackWebhookUrl($slackWebhookUrl);
        $actions = $this->buildBaseSlackActions($notificationData);
        $actionsContainer = new DataObject(['actions' => $actions]);

        try {
            $this->eventManager->dispatch('fera_review_update_slack_actions_prepare', [
                'message' => $message,
                'order' => $order,
                'store_id' => $storeId,
                'actions_container' => $actionsContainer,
            ]);

            $actionsData = $actionsContainer->getData('actions');
            if (!is_array($actionsData)) {
                throw new UnexpectedValueException(
                    'Incorrect type for actions: expected array, got ' . get_debug_type($actionsData)
                );
            }

            /** @var list<array<string, mixed>> $actions */
            $actions = $actionsData;
        } catch (Throwable $exception) {
            $this->logger->error(
                'Review update Slack action enrichment failed: ' . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'store_id' => $storeId,
                    'review_id' => $message->getReviewId(),
                ]
            );
        }

        $changedFields = $this->decodeChangedFields($message->getChangedFieldsJson());
        $this->sendSlack($slackWebhookUrl, $notificationData, $changedFields, $actions);
    }

    private function buildFeraReviewUrl(int $storeId, string $feraStoreId, string $reviewId): string
    {
        $appUrl = $this->getConfigString(ConfigOptionInterface::APP_URL, $storeId);
        if ($appUrl === '') {
            $appUrl = 'https://app.fera.ai';
        }
        $appUrl = rtrim($appUrl, '/');

        $targetUrl = $appUrl . '/customer_reviews/' . rawurlencode($reviewId);
        return $appUrl
            . '/stores/'
            . rawurlencode($feraStoreId)
            . '/switch?referrer='
            . rawurlencode($targetUrl);
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
     * @return array{url: string, increment_id: string, order: OrderInterface|null}
     */
    private function resolveOrderData(string $externalOrderId): array
    {
        $orderId = trim($externalOrderId);
        if ($orderId === '' || !is_numeric($orderId)) {
            return ['url' => '', 'increment_id' => '', 'order' => null];
        }

        try {
            $order = $this->orderRepository->get((int) $orderId);
        } catch (NoSuchEntityException) {
            return ['url' => '', 'increment_id' => '', 'order' => null];
        }

        if (!$order instanceof OrderInterface) {
            throw new UnexpectedValueException(
                'Incorrect type for order: expected ' . OrderInterface::class . ', got ' . get_debug_type($order)
            );
        }

        $entityId = $order->getEntityId();
        if (!is_numeric($entityId)) {
            throw new UnexpectedValueException(
                'Incorrect type for order entity ID: expected int, got ' . get_debug_type($entityId)
            );
        }

        $incrementId = $order->getIncrementId();
        if (!is_string($incrementId)) {
            throw new UnexpectedValueException(
                'Incorrect type for order increment ID: expected string, got ' . get_debug_type($incrementId)
            );
        }

        return [
            'url' => $this->backendUrl->getUrl('sales/order/view', ['order_id' => (int) $entityId]),
            'increment_id' => trim($incrementId),
            'order' => $order,
        ];
    }

    /**
     * @param NotificationData $notificationData
     * @return list<array<string, mixed>>
     */
    private function buildBaseSlackActions(array $notificationData): array
    {
        $actions = [];
        if ($notificationData['fera_review_url'] !== '') {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View in Fera',
                ],
                'url' => $notificationData['fera_review_url'],
                'style' => 'primary',
            ];
        }

        if ($notificationData['magento_order_url'] !== '') {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View Magento Order',
                ],
                'url' => $notificationData['magento_order_url'],
            ];
        }

        return $actions;
    }

    /**
     * @param NotificationData $notificationData
     * @param ChangedFields $changedFields
     * @param list<array<string, mixed>> $actions
     */
    private function sendSlack(
        string $webhookUrl,
        array $notificationData,
        array $changedFields,
        array $actions
    ): void {
        $blocks = [
            [
                'type' => 'header',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'Fera Review Update',
                    'emoji' => true,
                ],
            ],
            ['type' => 'divider'],
        ];

        array_push($blocks, ...$this->buildReviewBeforeBlocks($notificationData, $changedFields));
        array_push($blocks, ...$this->buildAfterChangesBlocks($changedFields));

        if ($actions !== []) {
            $blocks[] = [
                'type' => 'actions',
                'elements' => $actions,
            ];
        }

        $client = $this->clientFactory->create();
        $client->post($webhookUrl, [
            'json' => [
                'text' => 'Fera Review Update',
                'blocks' => $blocks,
            ],
            'connect_timeout' => self::SLACK_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::SLACK_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * @param NotificationData $notificationData
     * @param ChangedFields $changedFields
     * @return list<array<string, mixed>>
     */
    private function buildReviewBeforeBlocks(array $notificationData, array $changedFields): array
    {
        $rating = $changedFields['rating']['before'] ?? $notificationData['rating'];
        $title = $changedFields['heading']['before'] ?? $notificationData['review_title'];
        $review = $changedFields['body']['before'] ?? $notificationData['review_body'];

        $blocks = [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Review before changes*',
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Store:*\n" . $this->escapeSlack($notificationData['store_name']),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => "*Rating:*\n" . $this->formatChangedValue('rating', $rating),
                    ],
                ],
            ],
            [
                'type' => 'section',
                'fields' => [
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Product:* ' . $this->escapeSlack($notificationData['product_name']),
                    ],
                    [
                        'type' => 'mrkdwn',
                        'text' => '*Order ID:* ' . $this->escapeSlack($notificationData['external_order_id']),
                    ],
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Customer:* ' . $this->escapeSlack($notificationData['customer_name']),
                ],
            ],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*Title:* ' . $this->formatChangedValue('heading', $title) . "\n"
                        . '*Review:* ' . $this->formatChangedValue('body', $review),
                ],
            ],
        ];

        return $blocks;
    }

    /**
     * @param ChangedFields $changedFields
     * @return list<array<string, mixed>>
     */
    private function buildAfterChangesBlocks(array $changedFields): array
    {
        $afterBlocks = [];
        $compactFields = [];

        if (isset($changedFields['rating']['after'])) {
            $compactFields[] = [
                'type' => 'mrkdwn',
                'text' => $this->formatChangedFieldText('rating', 'Rating', $changedFields['rating']['after']),
            ];
        }

        if ($compactFields !== []) {
            $afterBlocks[] = [
                'type' => 'section',
                'fields' => $compactFields,
            ];
        }

        $reviewText = $this->buildAfterReviewText($changedFields);
        if ($reviewText !== '') {
            $afterBlocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $reviewText,
                ],
            ];
        }

        $newMedia = [];
        if (isset($changedFields['media']['after'])) {
            $newMedia = $this->extractNewMediaAttachments(
                $changedFields['media']['before'] ?? [],
                $changedFields['media']['after']
            );
        }

        if ($newMedia !== []) {
            $afterBlocks[] = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => $this->formatChangedFieldText('media', 'New media attached', $newMedia),
                ],
            ];
        }

        if ($afterBlocks === []) {
            return [];
        }

        return [
            ['type' => 'divider'],
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => '*After changes*',
                ],
            ],
            ...$afterBlocks,
        ];
    }

    /**
     * @param ChangedFields $changedFields
     */
    private function buildAfterReviewText(array $changedFields): string
    {
        $fields = [];
        if (isset($changedFields['heading']['after'])) {
            $fields[] = $this->formatChangedFieldText('heading', 'Title', $changedFields['heading']['after']);
        }

        if (isset($changedFields['body']['after'])) {
            $fields[] = $this->formatChangedFieldText('body', 'Review', $changedFields['body']['after']);
        }

        return implode("\n", $fields);
    }

    private function formatChangedFieldText(string $field, string $label, mixed $value): string
    {
        $separator = $field === 'media' ? "\n" : ' ';
        return '*' . $label . ':*' . $separator . $this->formatChangedValue($field, $value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function extractNewMediaAttachments(mixed $before, mixed $after): array
    {
        if (!is_array($after)) {
            return [];
        }

        $beforeKeys = $this->buildMediaComparisonKeys($before);
        $newMedia = [];
        foreach ($after as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemKeys = $this->extractMediaComparisonKeys($item);
            if ($itemKeys === [] || array_intersect($itemKeys, $beforeKeys) !== []) {
                continue;
            }

            $newMedia[] = $item;
        }

        return $newMedia;
    }

    /**
     * @return list<string>
     */
    private function buildMediaComparisonKeys(mixed $media): array
    {
        if (!is_array($media)) {
            return [];
        }

        $keys = [];
        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ($this->extractMediaComparisonKeys($item) as $key) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * @param array<string, mixed> $item
     * @return list<string>
     */
    private function extractMediaComparisonKeys(array $item): array
    {
        $id = $this->stringifyMediaValue($item['id'] ?? null);
        if ($id !== '') {
            return ['id:' . $id];
        }

        $url = $this->stringifyMediaValue($item['url'] ?? null);
        if ($url !== '') {
            return ['url:' . $url];
        }

        return [];
    }

    private function stringifyMediaValue(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * @return ChangedFields
     */
    private function decodeChangedFields(string $changedFieldsJson): array
    {
        $decoded = json_decode($changedFieldsJson, true);
        if (!is_array($decoded)) {
            throw new UnexpectedValueException(
                'Invalid changed fields JSON: ' . json_last_error_msg()
            );
        }

        $changedFields = [];
        foreach ($decoded as $field => $change) {
            if (
                !is_string($field)
                || !is_array($change)
                || !array_key_exists('before', $change)
                || !array_key_exists('after', $change)
            ) {
                throw new UnexpectedValueException('Invalid changed fields structure in message');
            }

            $changedFields[$field] = [
                'before' => $change['before'],
                'after' => $change['after'],
            ];
        }

        return $changedFields;
    }

    private function formatChangedValue(string $field, mixed $value): string
    {
        if ($field === 'rating' && is_numeric($value)) {
            return $this->formatRating((float) $value);
        }

        if ($field === 'media') {
            return $this->formatMediaValue($value);
        }

        if (is_scalar($value)) {
            return $this->truncateSlackFieldText($this->escapeSlack((string) $value));
        }

        $encoded = json_encode($value);
        return $this->truncateSlackFieldText($this->escapeSlack(is_string($encoded) ? $encoded : ''));
    }

    private function formatMediaValue(mixed $value): string
    {
        if (!is_array($value)) {
            return '-';
        }

        $urls = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $url = $item['url'] ?? null;
            if (is_string($url) && trim($url) !== '') {
                $urls[] = trim($url);
            }
        }

        if ($urls === []) {
            return '-';
        }

        return $this->truncateSlackFieldText($this->escapeSlack(implode("\n", $urls)));
    }

    private function formatRating(float $rating): string
    {
        $rounded = round($rating, 2);
        return rtrim(rtrim((string) $rounded, '0'), '.') . '/5';
    }

    private function getConfigString(string $path, int $storeId): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function assertSlackWebhookUrl(string $webhookUrl): void
    {
        $parsedUrl = parse_url($webhookUrl);
        if (!is_array($parsedUrl)) {
            throw new RuntimeException('Invalid Slack webhook URL format');
        }

        $scheme = $parsedUrl['scheme'] ?? null;
        $host = $parsedUrl['host'] ?? null;
        if (
            !is_string($scheme)
            || !in_array(strtolower($scheme), ['http', 'https'], true)
            || !is_string($host)
            || $host === ''
        ) {
            throw new RuntimeException('Slack webhook URL must be an absolute HTTP(S) URL');
        }
    }

    private function fallback(string $value): string
    {
        $trimmedValue = trim($value);
        if ($trimmedValue === '') {
            return '-';
        }

        return $trimmedValue;
    }

    private function escapeSlack(string $value): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $value);
    }

    private function truncateSlackFieldText(string $value): string
    {
        if (mb_strlen($value) <= self::MAX_SLACK_FIELD_TEXT_LENGTH) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::MAX_SLACK_FIELD_TEXT_LENGTH - 3)) . '...';
    }
}
