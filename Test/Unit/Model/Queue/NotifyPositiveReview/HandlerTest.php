<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model\Queue\NotifyPositiveReview;

use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Logger\Logger;
use Fera\Ai\Model\Queue\NotifyPositiveReview\Handler;
use Fera\Ai\Model\Queue\NotifyPositiveReview\Message;
use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class HandlerTest extends TestCase
{
    public function testProcessUsesPositiveSlackDestinationAndRendersMedia(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                'https://hooks.example/positive',
                self::anything()
            )
            ->willReturnCallback(function (string $url, array $options): ResponseInterface {
                self::assertSame('https://hooks.example/positive', $url);
                self::assertSame(2.0, $options['connect_timeout'] ?? null);
                self::assertSame(5.0, $options['timeout'] ?? null);
                $encoded = json_encode($options['json'] ?? [], JSON_UNESCAPED_SLASHES);
                self::assertIsString($encoded);
                self::assertStringContainsString('Positive Review Alert', $encoded);
                self::assertStringContainsString('View in Fera', $encoded);
                self::assertStringContainsString('https://cdn.example/photo.jpg', $encoded);
                self::assertStringContainsString('https://cdn.example/video.mp4', $encoded);
                self::assertStringNotContainsString('thumbnail', $encoded);

                return $this->createMock(ResponseInterface::class);
            });

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->expects(self::once())
            ->method('dispatch')
            ->with(
                'fera_positive_review_slack_actions_prepare',
                self::callback(static fn (array $payload): bool => $payload['store_id'] === 1)
            );

        $handler = $this->createHandler($client, $eventManager);
        $handler->process($this->createMessage());
    }

    public function testActionEventFailureStillSendsBasePositiveNotification(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('post');

        $eventManager = $this->createMock(EventManager::class);
        $eventManager->method('dispatch')->willThrowException(new RuntimeException('observer failed'));

        $handler = $this->createHandler($client, $eventManager);
        $handler->process($this->createMessage());
    }

    public function testDisabledPositiveNotificationsSkipSlackDelivery(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('post');

        $handler = $this->createHandler($client, $this->createMock(EventManager::class), false);
        $handler->process($this->createMessage());
    }

    public function testRatingBelowPositiveThresholdSkipsSlackDelivery(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('post');

        $handler = $this->createHandler($client, $this->createMock(EventManager::class), true, 5);
        $handler->process($this->createMessage(4));
    }

    public function testMissingOptionalContextStillSendsPositiveNotification(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                'https://hooks.example/positive',
                self::callback(function (array $options): bool {
                    $payload = $options['json'] ?? [];
                    $encoded = json_encode($payload);
                    return is_string($encoded)
                        && str_contains($encoded, 'Positive Review Alert')
                        && str_contains($encoded, 'View in Fera')
                        && !str_contains($encoded, '*Media:*');
                })
            );

        $message = $this->createMessage()
            ->setExternalOrderId('')
            ->setCustomerName('')
            ->setReviewTitle('')
            ->setReviewBody('')
            ->setProductName('')
            ->setMediaJson('[]');

        $handler = $this->createHandler($client, $this->createMock(EventManager::class));
        $handler->process($message);
    }

    public function testMalformedMediaJsonOmitsMediaSection(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                'https://hooks.example/positive',
                self::callback(function (array $options): bool {
                    $payload = $options['json'] ?? [];
                    $encoded = json_encode($payload);
                    return is_string($encoded)
                        && str_contains($encoded, 'Positive Review Alert')
                        && !str_contains($encoded, '*Media:*');
                })
            );

        $message = $this->createMessage()->setMediaJson('{invalid');

        $handler = $this->createHandler($client, $this->createMock(EventManager::class));
        $handler->process($message);
    }

    private function createHandler(
        Client $client,
        EventManager $eventManager,
        bool $enabled = true,
        int $threshold = 4
    ): Handler {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with(ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 1)
            ->willReturn($enabled);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                [ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_RATING_THRESHOLD, ScopeInterface::SCOPE_STORE, 1, $threshold],
                [
                    ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL,
                    ScopeInterface::SCOPE_STORE,
                    1,
                    'https://hooks.example/positive',
                ],
                [ConfigOptionInterface::APP_URL, ScopeInterface::SCOPE_STORE, 1, 'https://app.fera.ai'],
            ]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Main Store');
        $store->method('getCode')->willReturn('main');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(1)->willReturn($store);

        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($client);

        return new Handler(
            $scopeConfig,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(UrlInterface::class),
            $clientFactory,
            $storeManager,
            $this->createMock(Logger::class),
            $eventManager
        );
    }

    private function createMessage(float $rating = 5): Message
    {
        return (new Message())
            ->setStoreId(1)
            ->setReviewId('review-1')
            ->setRating($rating)
            ->setFeraStoreId('fera-store')
            ->setExternalOrderId('')
            ->setCustomerName('Ada')
            ->setCustomerEmail('ada@example.com')
            ->setReviewTitle('Lovely')
            ->setReviewBody('Wonderful')
            ->setProductName('Plush')
            ->setExternalProductId('sku-1')
            ->setMediaJson($this->encodeMedia([
                ['id' => 'photo-1', 'url' => 'https://cdn.example/photo.jpg'],
                ['id' => 'video-1', 'url' => 'https://cdn.example/video.mp4'],
            ]));
    }

    /**
     * @param list<array{id: string, url: string}> $media
     */
    private function encodeMedia(array $media): string
    {
        $mediaJson = json_encode($media, JSON_INVALID_UTF8_SUBSTITUTE);
        self::assertIsString($mediaJson);

        return $mediaJson;
    }
}
