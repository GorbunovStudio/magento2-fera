<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model;

use Fera\Ai\Api\Data\Queue\NotifyReviewUpdate\MessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\NotifyReviewUpdate\MessageInterface;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Model\ReviewUpdatedWebhook;
use Fera\Ai\Services\FeraWebhookJwtValidator;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotComparator;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewUpdatedWebhookTest extends TestCase
{
    public function testTranslatesInvalidSnapshotArgumentToBadRequest(): void
    {
        $payload = ['id' => ''];
        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('jwt')->willReturn('jwt');
        $request->method('getBodyParams')->willReturn($payload);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(9);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);
        $jwtValidator = $this->createMock(FeraWebhookJwtValidator::class);
        $jwtValidator->method('validateToken')->willReturn(['store_id' => 'fera-store']);
        $snapshotBuilder = $this->createMock(SnapshotBuilder::class);
        $snapshotBuilder->method('build')->willThrowException(
            new InvalidArgumentException('Field "id" must be a non-empty string')
        );
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->expects(self::once())->method('getCanonicalStoreId')->with(9)->willReturn(7);

        $this->expectException(WebapiException::class);
        $this->expectExceptionMessage('Field "id" must be a non-empty string');

        $webhook = new ReviewUpdatedWebhook(
            $request,
            $this->createMock(ScopeConfigInterface::class),
            $storeManager,
            $this->createMock(PublisherInterface::class),
            $jwtValidator,
            $this->createMock(MessageInterfaceFactory::class),
            $snapshotBuilder,
            $this->createMock(SnapshotRepository::class),
            $this->createMock(SnapshotComparator::class),
            $this->createMock(LockManagerInterface::class),
            $storeGroupService
        );

        $webhook->execute();
    }

    public function testChangedUpdatePublishesTheCompleteReviewBody(): void
    {
        $this->runUpdate('approved', ['rating'], str_repeat('a', 201));
    }

    public function testDuplicateDeliveryIsPersistedWithoutPublishing(): void
    {
        $this->runUpdate('pending_update', []);
    }

    /**
     * @param list<string> $changedFields
     */
    private function runUpdate(string $state, array $changedFields, string $reviewBody = ''): void
    {
        $payload = ['id' => 'review-1', 'state' => $state];
        $snapshot = [
            'review_id' => 'review-1',
            'heading' => '',
            'body' => $reviewBody,
            'rating' => 5.0,
            'media' => [],
            'magento_store_id' => 7,
            'subject' => 'product',
            'external_product_id' => '42',
            'fera_product_id' => 'fpro-1',
            'product_name' => 'Product',
            'state' => $state,
            'is_test' => false,
            'fera_created_at' => '2026-07-13 00:00:00',
            'fera_updated_at' => '2026-07-13 00:00:00',
        ];

        $request = $this->createMock(Request::class);
        $request->method('getParam')->with('jwt')->willReturn('jwt');
        $request->method('getBodyParams')->willReturn($payload);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(9);
        $storeManager->method('getStore')->willReturn($store);
        $jwtValidator = $this->createMock(FeraWebhookJwtValidator::class);
        $jwtValidator->method('validateToken')->willReturn(['store_id' => 'fera-store']);
        $snapshotBuilder = $this->createMock(SnapshotBuilder::class);
        $snapshotBuilder->method('build')->with($payload, 7)->willReturn($snapshot);
        $snapshotRepository = $this->createMock(SnapshotRepository::class);
        $snapshotRepository->method('getByReviewId')->with('review-1')->willReturn($snapshot);
        $snapshotRepository->expects(self::once())->method('save')->with($snapshot);
        $snapshotComparator = $this->createMock(SnapshotComparator::class);
        $snapshotComparator->method('compare')->willReturn(
            $changedFields === [] ? [] : ['rating' => ['before' => 4.0, 'after' => 5.0]]
        );
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->expects(self::once())->method('getCanonicalStoreId')->with(9)->willReturn(7);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects($changedFields === [] ? self::never() : self::once())
            ->method('isSetFlag')
            ->with(
                ConfigOptionInterface::REVIEW_UPDATE_NOTIFICATIONS_ENABLED,
                ScopeInterface::SCOPE_STORE,
                9
            )
            ->willReturn(true);
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($changedFields === [] ? self::never() : self::once())->method('publish')->with(
            TopicInterface::NOTIFY_REVIEW_UPDATE,
            self::anything()
        );
        $message = $this->createMock(MessageInterface::class);
        $message->method('setStoreId')->willReturnSelf();
        $message->method('setReviewId')->willReturnSelf();
        $message->method('setRating')->willReturnSelf();
        $message->method('setFeraStoreId')->willReturnSelf();
        $message->method('setExternalOrderId')->willReturnSelf();
        $message->method('setCustomerName')->willReturnSelf();
        $message->method('setCustomerEmail')->willReturnSelf();
        $message->method('setReviewTitle')->willReturnSelf();
        $message->expects($changedFields === [] ? self::never() : self::once())
            ->method('setReviewBody')
            ->with($reviewBody)
            ->willReturnSelf();
        $message->method('setProductName')->willReturnSelf();
        $message->method('setExternalProductId')->willReturnSelf();
        $message->method('setChangedFieldsJson')->willReturnSelf();
        $messageFactory = $this->createMock(MessageInterfaceFactory::class);
        $messageFactory->expects($changedFields === [] ? self::never() : self::once())
            ->method('create')
            ->willReturn($message);
        $lockManager = $this->createMock(LockManagerInterface::class);
        $lockManager->expects(self::once())->method('lock')->willReturn(true);
        $lockManager->expects(self::once())->method('unlock');

        $webhook = new ReviewUpdatedWebhook(
            $request,
            $scopeConfig,
            $storeManager,
            $publisher,
            $jwtValidator,
            $messageFactory,
            $snapshotBuilder,
            $snapshotRepository,
            $snapshotComparator,
            $lockManager,
            $storeGroupService
        );

        $webhook->execute();
    }
}
