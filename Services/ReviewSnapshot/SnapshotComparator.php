<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

/**
 * @phpstan-import-type ReviewSnapshot from SnapshotBuilder
 */
class SnapshotComparator
{
    /**
     * @param ReviewSnapshot $previous
     * @param ReviewSnapshot $current
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function compare(array $previous, array $current): array
    {
        $changes = [];
        foreach (['rating', 'heading', 'body', 'media'] as $field) {
            if ($previous[$field] === $current[$field]) {
                continue;
            }

            $changes[$field] = [
                'before' => $previous[$field],
                'after' => $current[$field],
            ];
        }

        return $changes;
    }
}
