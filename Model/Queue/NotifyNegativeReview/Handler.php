<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\NotifyNegativeReview;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Logger\Logger;
use GuzzleHttp\ClientFactory;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class Handler
{
    private const SLACK_CONNECT_TIMEOUT_SECONDS = 2.0;
    private const SLACK_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private ScopeConfigInterface $scopeConfig,
        private OrderRepositoryInterface $orderRepository,
        private SearchCriteriaBuilder $searchCriteriaBuilder,
        private UrlInterface $backendUrl,
        private TransportBuilder $transportBuilder,
        private EmailAddress $emailAddressValidator,
        private ClientFactory $clientFactory,
        private EncryptorInterface $encryptor,
        private StoreManagerInterface $storeManager,
        private Logger $logger
    ) {
    }

    public function process(MessageInterface $message): void
    {
        try {
            $this->processMessage($message);
        } catch (Throwable $exception) {
            $this->logger->error(
                'Unable to process negative review notification: ' . $exception->getMessage(),
                [
                    'exception' => $exception,
                    'store_id' => $message->getStoreId(),
                    'review_id' => $message->getReviewId(),
                ]
            );

            throw new RuntimeException(
                'Unable to process negative review notification: ' . $exception->getMessage(),
                (int) $exception->getCode(),
                $exception
            );
        } finally {
            if (method_exists($this->orderRepository, '_resetState')) {
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

        if (!$this->scopeConfig->isSetFlag(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        )) {
            return;
        }

        $threshold = $this->getRatingThreshold($storeId);
        if ($message->getRating() > $threshold) {
            return;
        }

        $store = $this->storeManager->getStore($storeId);
        $storeName = $store->getName();
        $storeCode = $store->getCode();

        $feraReviewUrl = $this->buildFeraReviewUrl($storeId, $message->getFeraStoreId(), $message->getReviewId());
        $orderData = $this->resolveOrderData($message->getExternalOrderId());

        $orderIncrementId = $orderData['increment_id'];
        if ($orderIncrementId === '') {
            $orderIncrementId = $message->getExternalOrderId();
        }

        $notificationData = [
            'store_name' => $storeName,
            'store_code' => $storeCode,
            'rating' => $message->getRating(),
            'customer_name' => $this->fallback($message->getCustomerName()),
            'heading' => $this->fallback($message->getHeading()),
            'body' => $this->fallback($message->getBody()),
            'product_name' => $this->fallback($message->getProductName()),
            'external_order_id' => $this->fallback($message->getExternalOrderId()),
            'fera_review_url' => $feraReviewUrl,
            'magento_order_url' => $orderData['url'],
        ];

        $slackWebhookUrl = $this->getConfigString(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL,
            $storeId
        );

        if ($slackWebhookUrl !== '') {
            $this->assertWebhookUrl($slackWebhookUrl);
            $this->sendSlack($slackWebhookUrl, $notificationData);
        }

        $recipientConfig = $this->getConfigString(ConfigOptionInterface::REVIEW_NOTIFICATIONS_EMAIL_RECIPIENTS, $storeId);
        if ($recipientConfig !== '') {
            $this->sendEmail($recipientConfig, $notificationData, $storeId);
        }
    }

    private function getRatingThreshold(int $storeId): float
    {
        $value = $this->scopeConfig->getValue(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_RATING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (!is_numeric($value)) {
            throw new RuntimeException('Rating threshold is not configured for store ID ' . $storeId);
        }

        return (float) $value;
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
     * @return array{url: string, increment_id: string}
     */
    private function resolveOrderData(string $externalOrderId): array
    {
        $orderId = trim($externalOrderId);
        if ($orderId === '') {
            return ['url' => '', 'increment_id' => ''];
        }

        if (!is_numeric($orderId)) {
            return ['url' => '', 'increment_id' => ''];
        }

        try {
            $order = $this->orderRepository->get((int) $orderId);
        } catch (NoSuchEntityException) {
            return ['url' => '', 'increment_id' => ''];
        }

        if (!$order instanceof OrderInterface) {
            throw new UnexpectedValueException(
                'Incorrect type for order: expected ' . OrderInterface::class . ', got ' . get_debug_type($order)
            );
        }

        $orderId = $order->getEntityId();
        if (!is_numeric($orderId)) {
            throw new UnexpectedValueException(
                'Incorrect type for order entity ID: expected int, got ' . get_debug_type($orderId)
            );
        }

        $incrementId = $order->getIncrementId();
        if (!is_string($incrementId)) {
            throw new UnexpectedValueException(
                'Incorrect type for order increment ID: expected string, got ' . get_debug_type($incrementId)
            );
        }

        return [
            'url' => $this->backendUrl->getUrl('sales/order/view', ['order_id' => (int) $orderId]),
            'increment_id' => trim($incrementId),
        ];
    }

    /**
     * @param array<string, mixed> $notificationData
     */
    private function sendSlack(string $webhookUrl, array $notificationData): void
    {
        $storeName = is_string($notificationData['store_name']) ? $notificationData['store_name'] : '';
        $rating = is_numeric($notificationData['rating'] ?? null) ? (float) $notificationData['rating'] : 0.0;
        $starsString = $this->formatStarsString($rating);

        $productName = is_string($notificationData['product_name']) ? $notificationData['product_name'] : '';
        $externalOrderId = is_string($notificationData['external_order_id']) ? $notificationData['external_order_id'] : '-';
        $customerName = is_string($notificationData['customer_name']) ? $notificationData['customer_name'] : '-';
        $reviewHeading = is_string($notificationData['heading']) ? $notificationData['heading'] : '-';
        $reviewBody = is_string($notificationData['body']) ? $notificationData['body'] : '';

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

        if (is_string($notificationData['magento_order_url']) && $notificationData['magento_order_url'] !== '') {
            $actions[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View Magento Order',
                ],
                'url' => $notificationData['magento_order_url'],
            ];
        }

        $payload = [
            'text' => '🚨 Negative Review Alert',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => '🚨 Negative Review Alert',
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
                            . "*Title:* {$reviewHeading}\n"
                            . "*Review:*\n" . $reviewBody,
                    ],
                ],
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

    private function formatStarsString(float $rating): string
    {
        $maxStars = 5;
        $ratingRounded = max(0, min($maxStars, (int) round($rating)));

        return str_repeat('★', $ratingRounded)
            . str_repeat('☆', $maxStars - $ratingRounded)
            . ' (' . $ratingRounded . '/' . $maxStars . ')';
    }

    private function sendEmail(string $recipientConfig, array $notificationData, int $storeId): void
    {
        $recipients = $this->parseRecipients($recipientConfig);
        if ($recipients === []) {
            return;
        }

        $transportBuilder = $this->transportBuilder
            ->setTemplateIdentifier('fera_negative_review_notification')
            ->setTemplateOptions([
                'area' => Area::AREA_FRONTEND,
                'store' => $storeId,
            ])
            ->setTemplateVars($notificationData)
            ->setFromByScope('general', $storeId);

        foreach ($recipients as $recipient) {
            $transportBuilder->addTo($recipient);
        }

        $transportBuilder->getTransport()->sendMessage();
    }

    /**
     * @return list<string>
     */
    private function parseRecipients(string $recipientConfig): array
    {
        $parts = preg_split('/[\n,]+/', $recipientConfig);
        if (!is_array($parts)) {
            return [];
        }

        $recipients = [];
        $invalidEmails = [];
        foreach ($parts as $part) {
            $email = trim($part);
            if ($email === '') {
                continue;
            }

            if (!$this->emailAddressValidator->isValid($email)) {
                $invalidEmails[] = $email;
                continue;
            }

            $recipients[] = $email;
        }

        if ($invalidEmails !== []) {
            throw new RuntimeException(
                'Invalid email recipients configuration: ' . implode(', ', $invalidEmails)
            );
        }

        return $recipients;
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
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true) || !is_string($host) || $host === '') {
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
