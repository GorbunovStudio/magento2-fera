<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit\Services\ReviewSnapshot;

use Fera\Ai\Services\ReviewSnapshot\MediaNormalizer;
use PHPUnit\Framework\TestCase;

class MediaNormalizerTest extends TestCase
{
    public function testNormalizeUsesFullUrlsAndIgnoresMalformedMedia(): void
    {
        $normalizer = new MediaNormalizer();

        self::assertSame(
            [
                ['id' => 'photo-1', 'url' => 'https://cdn.example/photo.jpg'],
                ['id' => 'video-1', 'url' => 'https://cdn.example/video.mp4'],
            ],
            $normalizer->normalize([
                [
                    'id' => 'video-1',
                    'url' => ' https://cdn.example/video.mp4 ',
                    'thumbnail_url' => 'https://cdn.example/video-thumb.jpg',
                ],
                [
                    'id' => 'missing-url',
                    'thumbnail_url' => 'https://cdn.example/photo-thumb.jpg',
                ],
                [
                    'id' => 'photo-1',
                    'url' => 'https://cdn.example/photo.jpg',
                ],
                'not-media',
                [
                    'id' => 'blank-url',
                    'url' => ' ',
                ],
            ])
        );
    }
}
