<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\NotifyPositiveReview\MessageInterfaceFactory as PositiveMessageInterfaceFactory;
use Fera\Ai\Api\Data\Queue\TopicInterface;
use Fera\Ai\Interface\ConfigOptionInterface;
use Fera\Ai\Model\ReviewCreatedWebhook;
use Fera\Ai\Model\Queue\NotifyNegativeReview\Message;
use Fera\Ai\Services\FeraWebhookJwtValidator;
use Fera\Ai\Services\ReviewSnapshot\MediaNormalizer;
use Fera\Ai\Services\ReviewSnapshot\ReviewSnapshotLock;
use Fera\Ai\Services\ReviewSnapshot\SnapshotBuilder;
use Fera\Ai\Services\ReviewSnapshot\SnapshotRepository;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Webapi\Exception as WebapiException;
use Magento\Framework\Webapi\Rest\Request;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewCreatedWebhookTest extends TestCase
{
    public function testPublishesCompleteReviewBodyToNegativeReviewQueue(): void
    {
        $reviewBody = rtrim(str_repeat('This is a detailed customer review. ', 8));
        $payload = [
            'id' => 'review-1',
            'rating' => 1,
            'body' => $reviewBody,
        ];
        $snapshot = [
            'review_id' => 'review-1',
            'heading' => '',
            'body' => $reviewBody,
            'rating' => 1.0,
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
        $snapshotBuilder->expects(self::once())->method('build')->with($payload, 7)->willReturn($snapshot);
        $snapshotRepository = $this->createMock(SnapshotRepository::class);
        $snapshotRepository->expects(self::once())->method('save')->with($snapshot);
        $snapshotLock = $this->createMock(ReviewSnapshotLock::class);
        $snapshotLock->expects(self::once())
            ->method('execute')
            ->with('review-1', self::isType('callable'))
            ->willReturnCallback(static function (string $reviewId, callable $operation): mixed {
                return $operation();
            });
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->expects(self::once())->method('getCanonicalStoreId')->with(9)->willReturn(7);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturnMap([
            [ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 9, true],
            [ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 9, false],
        ]);
        $scopeConfig->method('getValue')->with(
            ConfigOptionInterface::REVIEW_NOTIFICATIONS_RATING_THRESHOLD,
            ScopeInterface::SCOPE_STORE,
            9
        )->willReturn(3);

        $message = (new Message())
            ->setStoreId(9)
            ->setReviewId('review-1')
            ->setRating(1.0)
            ->setFeraStoreId('fera-store')
            ->setExternalOrderId('')
            ->setCustomerName('')
            ->setCustomerEmail('')
            ->setReviewTitle('')
            ->setReviewBody('')
            ->setProductName('')
            ->setExternalProductId('')
            ->setMediaJson('[]');
        $negativeMessageFactory = $this->createMock(MessageInterfaceFactory::class);
        $negativeMessageFactory->expects(self::once())->method('create')->willReturn($message);

        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->with(
                TopicInterface::NOTIFY_NEGATIVE_REVIEW,
                self::callback(static function (Message $publishedMessage) use ($reviewBody): bool {
                    return $publishedMessage->getReviewBody() === $reviewBody;
                })
            );

        $webhook = new ReviewCreatedWebhook(
            $request,
            $scopeConfig,
            $storeManager,
            $publisher,
            $jwtValidator,
            $negativeMessageFactory,
            $this->createMock(PositiveMessageInterfaceFactory::class),
            new MediaNormalizer(),
            $snapshotBuilder,
            $snapshotRepository,
            $snapshotLock,
            $storeGroupService
        );

        $webhook->execute();
    }

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

        $webhook = new ReviewCreatedWebhook(
            $request,
            $this->createMock(ScopeConfigInterface::class),
            $storeManager,
            $this->createMock(PublisherInterface::class),
            $jwtValidator,
            $this->createMock(MessageInterfaceFactory::class),
            $this->createMock(PositiveMessageInterfaceFactory::class),
            new MediaNormalizer(),
            $snapshotBuilder,
            $this->createMock(SnapshotRepository::class),
            $this->createMock(ReviewSnapshotLock::class),
            $storeGroupService
        );

        $webhook->execute();
    }

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
        /** @var ReviewSnapshotLock&MockObject $snapshotLock */
        $snapshotLock = $this->createMock(ReviewSnapshotLock::class);
        $snapshotLock->expects(self::once())
            ->method('execute')
            ->with('review-1', self::isType('callable'))
            ->willReturnCallback(static function (string $reviewId, callable $operation): mixed {
                return $operation();
            });
        /** @var StoreGroupService&MockObject $storeGroupService */
        $storeGroupService = $this->createMock(StoreGroupService::class);
        $storeGroupService->expects(self::once())->method('getCanonicalStoreId')->with(9)->willReturn(7);
        /** @var ScopeConfigInterface&MockObject $scopeConfig */
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::exactly(2))
            ->method('isSetFlag')
            ->willReturnMap([
                [ConfigOptionInterface::NEGATIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 9, false],
                [ConfigOptionInterface::POSITIVE_REVIEW_NOTIFICATIONS_ENABLED, ScopeInterface::SCOPE_STORE, 9, false],
            ]);
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
            $this->createMock(PositiveMessageInterfaceFactory::class),
            new MediaNormalizer(),
            $snapshotBuilder,
            $snapshotRepository,
            $snapshotLock,
            $storeGroupService
        );

        $webhook->execute();
    }
}
