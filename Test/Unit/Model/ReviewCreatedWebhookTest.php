<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Model\ReviewCreatedWebhook;
use Fera\Ai\Services\FeraWebhookJwtValidator;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Fera\Ai\Services\StoreGroupService;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewCreatedWebhookTest extends TestCase
{
    public function testPersistsCanonicalSnapshotWhenNotificationsAreDisabled(): void
    {
        $payload = ['id' => 'review-1'];
        $snapshot = [
            'review_id' => 'review-1',
            'heading' => '',
            'body' => '',
            'rating' => 5.0,
            'media' => [],
            'magento_store_id' => 7,
            'subject' => 'product',
            'external_product_id' => '42',
            'fera_product_id' => 'fpro-1',
            'product_name' => 'Product',
            'state' => 'approved',
            'is_test' => false,
            'fera_created_at' => '2026-07-13 00:00:00',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ];

        /** @var Request&MockObject $request */
        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('jwt')->willReturn('jwt');
        $request->method('getBodyParams')->willReturn($payload);
        /** @var StoreManagerInterface&MockObject $storeManager */
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(9);
        $storeManager->method('getStore')->willReturn($store);
        /** @var FeraWebhookJwtValidator&MockObject $jwtValidator */
        $jwtValidator = $this->createMock(FeraWebhookJwtValidator::class);
        $jwtValidator->method('validateToken')->willReturn(['store_id' => 'fera-store']);
        /** @var SnapshotBuilder&MockObject $snapshotBuilder */
        $snapshotBuilder = $this->createMock(SnapshotBuilder::class);
        $snapshotBuilder->expects(self::once())->method('build')->with($payload, 7)->willReturn($snapshot);
        /** @var SnapshotRepository&MockObject $snapshotRepository */
        $snapshotRepository = $this->createMock(SnapshotRepository::class);
        $snapshotRepository->expects(self::once())->method('save')->with($snapshot);
        /** @var StoreGroupService&MockObject $storeGroupService */
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->expects(self::once())->method('getCanonicalStoreId')->with(9)->willReturn(7);
        /** @var ScopeConfigInterface&MockObject $scopeConfig */
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with(ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 9)
            ->willReturn(false);
        /** @var PublisherInterface&MockObject $publisher */
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::never())->method('publish')->with(TopicInterface::NOTIFY_NEGATIVE_REVIEW, self::anything());

        $webhook = new ReviewCreatedWebhook(
            $request,
            $scopeConfig,
            $storeManager,
            $publisher,
            $jwtValidator,
            $this->createMock(MessageInterfaceFactory::class),
            $snapshotBuilder,
            $snapshotRepository,
            $storeGroupService
        );

        $webhook->execute();
    }
}
