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
        $orderUrl = $this->resolveOrderUrl($message->getExternalOrderId());

        $notificationData = [
            'store_name' => $storeName,
            'store_code' => $storeCode,
            'rating' => $message->getRating(),
            'customer_name' => $this->fallback($message->getCustomerName()),
            'heading' => $this->fallback($message->getHeading()),
            'body' => $this->truncate($this->fallback($message->getBody()), 100),
            'product_name' => $this->fallback($message->getProductName()),
            'external_order_id' => $this->fallback($message->getExternalOrderId()),
            'fera_review_url' => $feraReviewUrl,
            'magento_order_url' => $orderUrl,
        ];

        $slackWebhookUrl = $this->getConfigString(ConfigOptionInterface::REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL, $storeId);
        if ($slackWebhookUrl !== '') {
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

    private function resolveOrderUrl(string $externalOrderId): string
    {
        $orderId = trim($externalOrderId);
        if ($orderId === '') {
            return '';
        }

        if (!is_numeric($orderId)) {
            return '';
        }

        try {
            $order = $this->orderRepository->get((int) $orderId);
        } catch (NoSuchEntityException) {
            return '';
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

        return $this->backendUrl->getUrl('sales/order/view', ['order_id' => (int) $orderId]);
    }

    /**
     * @param array<string, mixed> $notificationData
     */
    private function sendSlack(string $webhookUrl, array $notificationData): void
    {
        $elements = [
            [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View in Fera',
                ],
                'url' => $notificationData['fera_review_url'],
            ],
        ];

        if (is_string($notificationData['magento_order_url']) && $notificationData['magento_order_url'] !== '') {
            $elements[] = [
                'type' => 'button',
                'text' => [
                    'type' => 'plain_text',
                    'text' => 'View Magento Order',
                ],
                'url' => $notificationData['magento_order_url'],
            ];
        }

        $payload = [
            'text' => 'Negative review received',
            'blocks' => [
                [
                    'type' => 'header',
                    'text' => [
                        'type' => 'plain_text',
                        'text' => 'Negative review received',
                    ],
                ],
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => '*Store:* ' . $notificationData['store_name'] . ' (`' . $notificationData['store_code'] . "`)\n"
                            . '*Rating:* ' . $notificationData['rating'] . "\n"
                            . '*Customer:* ' . $notificationData['customer_name'] . "\n"
                            . '*Product:* ' . $notificationData['product_name'] . "\n"
                            . '*Title:* ' . $notificationData['heading'] . "\n"
                            . '*Review:* ' . $notificationData['body'],
                    ],
                ],
                [
                    'type' => 'actions',
                    'elements' => $elements,
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

    private function fallback(string $value): string
    {
        $trimmedValue = trim($value);
        if ($trimmedValue === '') {
            return '-';
        }

        return $trimmedValue;
    }

    private function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength - 1)) . '…';
    }
}
