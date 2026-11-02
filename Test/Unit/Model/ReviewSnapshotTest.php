<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Model;

use Fera\Ai\Model\ReviewSnapshot;
use Fera\Ai\Model\ResourceModel\ReviewSnapshot as ReviewSnapshotResource;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Model\ActionValidator\RemoveAction;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReviewSnapshotTest extends TestCase
{
    public function testExternalOrderIdAccessorSupportsNullableStrings(): void
    {
        $context = $this->createStub(Context::class);
        $context->method('getAppState')->willReturn($this->createStub(State::class));
        $context->method('getEventDispatcher')->willReturn($this->createStub(ManagerInterface::class));
        $context->method('getCacheManager')->willReturn($this->createStub(CacheInterface::class));
        $context->method('getLogger')->willReturn($this->createStub(LoggerInterface::class));
        $context->method('getActionValidator')->willReturn($this->createStub(RemoveAction::class));
        $resource = $this->createStub(ReviewSnapshotResource::class);
        $resource->method('getIdFieldName')->willReturn('id');
        $model = new ReviewSnapshot($context, new Registry(), $resource);

        self::assertSame($model, $model->setExternalOrderId('order-42'));
        self::assertSame('order-42', $model->getExternalOrderId());

        $model->setExternalOrderId(null);
        self::assertNull($model->getExternalOrderId());
    }
}
