<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Api\Data\Queue;

use Fera\Ai\Api\Data\Queue\NotifyNegativeReview\MessageInterface as NegativeMessageInterface;
use Fera\Ai\Api\Data\Queue\NotifyPositiveReview\MessageInterface as PositiveMessageInterface;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ReviewMediaContractTest extends TestCase
{
    /**
     * @dataProvider createdReviewMessageContractsProvider
     */
    public function testCreatedReviewMessageContractsExposeMediaJsonInsteadOfRawMediaArray(string $interface): void
    {
        self::assertFalse(method_exists($interface, 'getMedia'));
        self::assertFalse(method_exists($interface, 'setMedia'));

        $getter = new ReflectionMethod($interface, 'getMediaJson');
        $setter = new ReflectionMethod($interface, 'setMediaJson');

        self::assertSame('string', (string) $getter->getReturnType());
        self::assertSame('string', (string) $setter->getParameters()[0]->getType());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function createdReviewMessageContractsProvider(): iterable
    {
        yield [PositiveMessageInterface::class];
        yield [NegativeMessageInterface::class];
    }
}
