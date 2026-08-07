<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model\Queue\NotifyReviewUpdate;

use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Logger\Logger;
use Fera\Ai\Model\Queue\NotifyReviewUpdate\Handler;
use Fera\Ai\Model\Queue\NotifyReviewUpdate\Message;
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

class HandlerTest extends TestCase
{
    public function testReviewSectionsAreTruncatedToSlackSectionLimit(): void
    {
        $reviewBody = str_repeat('Long review text. ', 400);
        $changedFields = json_encode([
            'body' => [
                'before' => $reviewBody,
                'after' => $reviewBody,
            ],
        ]);
        self::assertIsString($changedFields);

        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('post')
            ->with('https://hooks.example/review-update', self::anything())
            ->willReturnCallback(function (string $url, array $options): ResponseInterface {
                $reviewTexts = [];
                foreach ($options['json']['blocks'] ?? [] as $block) {
                    $text = $block['text']['text'] ?? null;
                    if (is_string($text) && str_contains($text, '*Review:*')) {
                        $reviewTexts[] = $text;
                    }
                }

                self::assertCount(2, $reviewTexts);
                foreach ($reviewTexts as $reviewText) {
                    self::assertLessThanOrEqual(3000, mb_strlen($reviewText));
                    self::assertStringEndsWith('...', $reviewText);
                }

                return $this->createMock(ResponseInterface::class);
            });

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')
            ->with(ConfigOptionInterface::REVIEW_UPDATE_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 1)
            ->willReturn(true);
        $scopeConfig->method('getValue')->willReturnMap([
            [ConfigOptionInterface::APP_URL, ScopeInterface::SCOPE_STORE, 1, 'https://app.fera.ai'],
            [
                ConfigOptionInterface::REVIEW_NOTIFICATIONS_SLACK_WEBHOOK_URL,
                ScopeInterface::SCOPE_STORE,
                1,
                'https://hooks.example/review-update',
            ],
        ]);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getName')->willReturn('Main Store');
        $store->method('getCode')->willReturn('main');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->with(1)->willReturn($store);

        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->method('create')->willReturn($client);

        $handler = new Handler(
            $scopeConfig,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(UrlInterface::class),
            $clientFactory,
            $storeManager,
            $this->createMock(Logger::class),
            $this->createMock(EventManager::class)
        );

        $message = (new Message())
            ->setStoreId(1)
            ->setReviewId('review-1')
            ->setRating(3.0)
            ->setFeraStoreId('fera-store')
            ->setExternalOrderId('')
            ->setCustomerName('Ada')
            ->setCustomerEmail('ada@example.com')
            ->setReviewTitle('Title')
            ->setReviewBody($reviewBody)
            ->setProductName('Plush')
            ->setExternalProductId('sku-1')
            ->setChangedFieldsJson($changedFields);

        $handler->process($message);
    }
}
