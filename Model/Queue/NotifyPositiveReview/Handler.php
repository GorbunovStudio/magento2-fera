<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\NotifyPositiveReview;

use Fera\Ai\Api\Data\Queue\NotifyPositiveReview\MessageInterface;
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
 *   magento_order_url: string,
 *   media: list<array{id: string, url: string}>
 * }
 */
class Handler
{
    private const SLACK_CONNECT_TIMEOUT_SECONDS = 2.0;
    private const SLACK_TIMEOUT_SECONDS = 5.0;

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
                'Unable to process positive review notification: ' . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'store_id' => $message->getStoreId(),
                    'review_id' => $message->getReviewId(),
                ]
            );

            throw new RuntimeException(
                'Unable to process positive review notification: ' . $exception->getMessage(),
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

        if (!$this->arePositiveReviewNotificationsEnabled($storeId)) {
            return;
        }

        $threshold = $this->getPositiveRatingThreshold($storeId);
        if ($message->getRating() < $threshold) {
            return;
        }

        $slackWebhookUrl = $this->getConfigString(
            ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL,
            $storeId
        );

        if ($slackWebhookUrl === '') {
            return;
        }

        $this->assertWebhookUrl($slackWebhookUrl);

        $store = $this->storeManager->getStore($storeId);
        $storeName = $store->getName();
        $storeCode = $store->getCode();

        $feraReviewUrl = $this->buildFeraReviewUrl($storeId, $message->getFeraStoreId(), $message->getReviewId());
        $orderData = $this->resolveOrderData($message->getExternalOrderId());
        $order = $orderData['order'];

        $orderIncrementId = $orderData['increment_id'];
        if ($orderIncrementId === '') {
            $orderIncrementId = $message->getExternalOrderId();
        }

        $notificationData = [
            'store_name' => $storeName,
            'store_code' => $storeCode,
            'rating' => $message->getRating(),
            'customer_name' => $this->fallback($message->getCustomerName()),
            'review_title' => $this->fallback($message->getReviewTitle()),
            'review_body' => $this->fallback($message->getReviewBody()),
            'product_name' => $this->fallback($message->getProductName()),
            'external_order_id' => $this->fallback($orderIncrementId),
            'fera_review_url' => $feraReviewUrl,
            'magento_order_url' => $orderData['url'],
            'media' => $this->decodeMediaJson($message->getMediaJson()),
        ];

        $actions = $this->buildBaseSlackActions($notificationData);
        $actionsContainer = new DataObject(['actions' => $actions]);

        try {
            $this->eventManager->dispatch('fera_positive_review_slack_actions_prepare', [
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
                'Positive review Slack action enrichment failed: ' . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'store_id' => $storeId,
                    'review_id' => $message->getReviewId(),
                ]
            );
        }

        $this->sendSlack($slackWebhookUrl, $notificationData, $actions);
    }

    private function getPositiveRatingThreshold(int $storeId): float
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_RATING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!is_numeric($value)) {
            throw new RuntimeException('Positive rating threshold is not configured for store ID ' . $storeId);
        }

        return (float) $value;
    }

    private function arePositiveReviewNotificationsEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
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

        $orderEntityId = $order->getEntityId();
        if (!is_numeric($orderEntityId)) {
            throw new UnexpectedValueException(
                'Incorrect type for order entity ID: expected int, got ' . get_debug_type($orderEntityId)
            );
        }

        $incrementId = $order->getIncrementId();
        if (!is_string($incrementId)) {
            throw new UnexpectedValueException(
                'Incorrect type for order increment ID: expected string, got ' . get_debug_type($incrementId)
            );
        }

        return [
            'url' => $this->backendUrl->getUrl('sales/order/view', ['order_id' => (int) $orderEntityId]),
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
        $actions = [
            [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View in Fera',
                ],
                'url' => $notificationData['fera_review_url'],
                'style' => 'primary',
            ],
        ];

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
     * @param list<array<string, mixed>> $actions
     */
    private function sendSlack(string $webhookUrl, array $notificationData, array $actions): void
    {
        $storeName = $notificationData['store_name'];
        $rating = $notificationData['rating'];
        $starsString = $this->formatStarsString($rating);

        $productName = $notificationData['product_name'];
        $externalOrderId = $notificationData['external_order_id'];
        $customerName = $notificationData['customer_name'];
        $reviewTitle = $notificationData['review_title'];
        $reviewBody = $notificationData['review_body'];

        $payload = [
            'text' => 'Positive Review Alert',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Positive Review Alert',
                        'emoji' => true,
                    ],
                ],
                [
                    'type' => 'divider',
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Store:*\n" . $storeName,
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Rating:*\n" . $starsString,
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'fields' => [
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Product:* {$productName}",
                        ],
                        [
                            'type' => 'mrkdwn',
                            'text' => "*Order ID:* {$externalOrderId}",
                        ],
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => "*Customer:* {$customerName}\n"
                            . "*Title:* {$reviewTitle}\n"
                            . "*Review:*\n" . $reviewBody,
                    ],
                ],
                ...$this->buildMediaBlocks($notificationData['media']),
                [
                    'type' => 'actions',
                    'elements' => $actions,
                ],
            ],
        ];

        $client = $this->clientFactory->create();
        $client->post($webhookUrl, [
            'json' => $payload,
            'connect_timeout' => self::SLACK_CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::SLACK_TIMEOUT_SECONDS,
        ]);
    }

    /**
     * @param list<array{id: string, url: string}> $media
     * @return list<array<string, mixed>>
     */
    private function buildMediaBlocks(array $media): array
    {
        $urls = [];
        foreach ($media as $item) {
            $url = trim($item['url']);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        if ($urls === []) {
            return [];
        }

        return [
            [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => "*Media:*\n" . implode("\n", $urls),
                ],
            ],
        ];
    }

    /**
     * @return list<array{id: string, url: string}>
     */
    private function decodeMediaJson(string $mediaJson): array
    {
        if (trim($mediaJson) === '') {
            return [];
        }

        $decoded = json_decode($mediaJson, true);
        if (!is_array($decoded)) {
            return [];
        }

        $media = [];
        foreach ($decoded as $item) {
            if (
                !is_array($item)
                || !is_string($item['id'] ?? null)
                || !is_string($item['url'] ?? null)
            ) {
                continue;
            }

            $media[] = [
                'id' => $item['id'],
                'url' => $item['url'],
            ];
        }

        return $media;
    }

    private function formatStarsString(float $rating): string
    {
        $maxStars = 5;
        $ratingRounded = max(0, min($maxStars, (int) round($rating)));

        return str_repeat('★', $ratingRounded)
            . str_repeat('☆', $maxStars - $ratingRounded)
            . ' (' . $ratingRounded . '/' . $maxStars . ')';
    }

    private function getConfigString(string $path, int $storeId): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
        if (!is_string($value)) {
            return '';
        }

        return trim($value);
    }

    private function assertWebhookUrl(string $webhookUrl): void
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
}
