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
        $context = $this->createMock(Context::class);
        $context->method('getAppState')->willReturn($this->createMock(State::class));
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));
        $context->method('getCacheManager')->willReturn($this->createMock(CacheInterface::class));
        $context->method('getLogger')->willReturn($this->createMock(LoggerInterface::class));
        $context->method('getActionValidator')->willReturn($this->createMock(RemoveAction::class));
        $resource = $this->createMock(ReviewSnapshotResource::class);
        $resource->method('getIdFieldName')->willReturn('id');
        $model = new ReviewSnapshot($context, new Registry(), $resource);

        self::assertSame($model, $model->setExternalOrderId('order-42'));
        self::assertSame('order-42', $model->getExternalOrderId());

        $model->setExternalOrderId(null);
        self::assertNull($model->getExternalOrderId());
    }
}
