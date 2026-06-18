<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

class MediaNormalizer
{
    /**
     * @param mixed $media
     * @return list<array{id: string, thumbnail_url: string}>
     */
    public function normalize(mixed $media): array
    {
        if (!is_array($media)) {
            return [];
        }

        $normalized = [];
        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = $this->extractString($item, 'id');
            $thumbnailUrl = $this->extractString($item, 'thumbnail_url');
            if ($id === '' && $thumbnailUrl === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'thumbnail_url' => $thumbnailUrl,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['id'], $left['thumbnail_url']]
                <=> [$right['id'], $right['thumbnail_url']]
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractString(array $item, string $key): string
    {
        $value = $item[$key] ?? null;
        if (is_string($value)) {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '';
    }
}
