<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ApiClient;

use Fera\Ai\Exception\FeraApiException;
use Fera\Ai\Services\ApiClient;
use Fera\Ai\Services\ApiClient\ReviewsClient;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ReviewsClientTest extends TestCase
{
    public function testListRejectsResponseWithoutData(): void
    {
        /** @var ApiClient&MockObject $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('get')->willReturn(['meta' => ['page_count' => 1]]);

        $this->expectException(FeraApiException::class);
        $this->expectExceptionMessage('field "data" must be an array');

        (new ReviewsClient($apiClient))->list(1, 50, 7);
    }

    public function testListRejectsDataWithInvalidType(): void
    {
        /** @var ApiClient&MockObject $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->method('get')->willReturn(['data' => 'invalid', 'meta' => []]);

        $this->expectException(FeraApiException::class);
        $this->expectExceptionMessage('field "data" must be an array');

        (new ReviewsClient($apiClient))->list(1, 50, 7);
    }

    public function testListRequestsBothReviewSubjectsWithoutStateOrVerificationFilters(): void
    {
        /** @var ApiClient&MockObject $apiClient */
        $apiClient = $this->createMock(ApiClient::class);
        $apiClient->expects(self::once())
            ->method('get')
            ->with(
                'v3/private/reviews',
                7,
                ['page' => 2, 'page_size' => 50, 'subject' => 'both']
            )
            ->willReturn([
                'data' => [['id' => 'frev_product_001']],
                'meta' => ['page_count' => 2],
            ]);

        $response = (new ReviewsClient($apiClient))->list(2, 50, 7);

        self::assertSame([['id' => 'frev_product_001']], $response['data']);
        self::assertSame(['page_count' => 2], $response['meta']);
    }
}
