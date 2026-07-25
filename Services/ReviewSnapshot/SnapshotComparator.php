<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

/**
 * @phpstan-import-type ReviewSnapshot from SnapshotBuilder
 * @phpstan-import-type ReviewMedia from SnapshotBuilder
 */
class SnapshotComparator
{
    /**
     * Compares the selected current review fields against their previous values.
     *
     * @param ReviewSnapshot $previous
     * @param ReviewSnapshot $current
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function compare(array $previous, array $current): array
    {
        $changes = [];
        foreach (['rating', 'heading', 'body'] as $field) {
            if ($previous[$field] === $current[$field]) {
                continue;
            }

            $changes[$field] = [
                'before' => $previous[$field],
                'after' => $current[$field],
            ];
        }

        if (!$this->hasSameMedia($previous['media'], $current['media'])) {
            $changes['media'] = [
                'before' => $previous['media'],
                'after' => $current['media'],
            ];
        }

        return $changes;
    }

    /**
     * Determines whether two normalized media lists have the same attachment identities.
     *
     * @param array $previous
     * @phpstan-param ReviewMedia $previous
     * @param array $current
     * @phpstan-param ReviewMedia $current
     */
    private function hasSameMedia(array $previous, array $current): bool
    {
        return $this->getMediaComparisonKeys($previous) === $this->getMediaComparisonKeys($current);
    }

    /**
     * Builds stable attachment comparison identities for a normalized media list.
     *
     * @param array $media
     * @phpstan-param ReviewMedia $media
     * @return list<string>
     */
    private function getMediaComparisonKeys(array $media): array
    {
        $keys = [];
        foreach ($media as $item) {
            $keys[] = $item['id'] !== '' ? 'id:' . $item['id'] : 'url:' . $item['url'];
        }

        sort($keys, SORT_STRING);

        return $keys;
    }
}
