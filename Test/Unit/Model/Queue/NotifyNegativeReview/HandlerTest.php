<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model\Queue\NotifyNegativeReview;

use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Logger\Logger;
use Fera\Ai\Model\Queue\NotifyNegativeReview\Handler;
use Fera\Ai\Model\Queue\NotifyNegativeReview\Message;
use GuzzleHttp\Client;
use GuzzleHttp\ClientFactory;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Validator\EmailAddress;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class HandlerTest extends TestCase
{
    public function testProcessRendersMediaUrlsInSlackPayload(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with(ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);
        $scopeConfig->method('getValue')
            ->willReturnMap([
                [ConfigOptionInterface::REVIEW_NOTIFICATIONS_RATING_THRESHOLD, ScopeInterface::SCOPE_STORE, 1, 3],
                [ConfigOptionInterface::APP_URL, ScopeInterface::SCOPE_STORE, 1, 'https://app.fera.ai'],
                [
                    ConfigOptionInterface::REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL,
                    ScopeInterface::SCOPE_STORE,
                    1,
                    'https://hooks.example/negative',
                ],
                [ConfigOptionInterface::REVIEW_NOTIFICATIONS_EMAIL_RECIPIENTS, ScopeInterface::SCOPE_STORE, 1, ''],
            ]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Main Store');
        $store->method('getCode')->willReturn('main');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(1)->willReturn($store);

        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                'https://hooks.example/negative',
                self::anything()
            )
            ->willReturnCallback(function (string $url, array $options): ResponseInterface {
                self::assertSame('https://hooks.example/negative', $url);
                self::assertSame(2.0, $options['connect_timeout'] ?? null);
                self::assertSame(5.0, $options['timeout'] ?? null);
                $encoded = json_encode($options['json'] ?? [], JSON_UNESCAPED_SLASHES);
                self::assertIsString($encoded);
                self::assertStringContainsString('https://cdn.example/photo.jpg', $encoded);
                self::assertStringContainsString('https://cdn.example/video.mp4', $encoded);

                $reviewText = $options['json']['blocks'][4]['text']['text'] ?? null;
                self::assertIsString($reviewText);
                self::assertLessThanOrEqual(3000, mb_strlen($reviewText));
                self::assertStringEndsWith('...', $reviewText);

                return $this->createMock(ResponseInterface::class);
            });

        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($client);

        $message = (new Message())
            ->setStoreId(1)
            ->setReviewId('review-1')
            ->setRating(2)
            ->setFeraStoreId('fera-store')
            ->setExternalOrderId('')
            ->setCustomerName('Ada')
            ->setCustomerEmail('ada@example.com')
            ->setReviewTitle('Oh no')
            ->setReviewBody(str_repeat('Not great. ', 400))
            ->setProductName('Plush')
            ->setExternalProductId('sku-1')
            ->setMediaJson($this->encodeMedia([
                ['id' => 'photo-1', 'url' => 'https://cdn.example/photo.jpg'],
                ['id' => 'video-1', 'url' => 'https://cdn.example/video.mp4'],
            ]));

        $handler = new Handler(
            $scopeConfig,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(UrlInterface::class),
            $this->createMock(TransportBuilder::class),
            $this->createMock(EmailAddress::class),
            $clientFactory,
            $storeManager,
            $this->createMock(Logger::class),
            $this->createMock(EventManager::class)
        );

        $handler->process($message);
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
