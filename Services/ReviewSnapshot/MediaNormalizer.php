<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

class MediaNormalizer
{
    /**
     * @param mixed $media
     * @return list<array{id: string, url: string}>
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
            $url = $this->extractString($item, 'url');
            if ($id === '' && $url === '') {
                continue;
            }

            $normalized[] = [
                'id' => $id,
                'url' => $url,
            ];
        }

        usort(
            $normalized,
            static fn (array $left, array $right): int => [$left['id'], $left['url']]
                <=> [$right['id'], $right['url']]
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
