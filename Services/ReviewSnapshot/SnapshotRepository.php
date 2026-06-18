<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * @phpstan-import-type ReviewSnapshot from SnapshotBuilder
 */
class SnapshotRepository
{
    private const TABLE_NAME = 'fera_review_snapshots';

    public function __construct(
        private ResourceConnection $resourceConnection,
        private Json $json,
        private MediaNormalizer $mediaNormalizer
    ) {
    }

    /**
     * @return ReviewSnapshot|null
     */
    public function getByReviewId(string $reviewId): ?array
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $row = $connection->fetchRow(
            $connection->select()
                ->from($tableName, ['review_id', 'heading', 'body', 'rating', 'media'])
                ->where('review_id = ?', $reviewId)
                ->limit(1)
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'review_id' => (string) ($row['review_id'] ?? ''),
            'heading' => (string) ($row['heading'] ?? ''),
            'body' => (string) ($row['body'] ?? ''),
            'rating' => (float) ($row['rating'] ?? 0),
            'media' => $this->decodeMedia((string) ($row['media'] ?? '[]')),
        ];
    }

    /**
     * @param ReviewSnapshot $snapshot
     */
    public function save(array $snapshot): void
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $connection->insertOnDuplicate(
            $tableName,
            [
                'review_id' => $snapshot['review_id'],
                'heading' => $snapshot['heading'],
                'body' => $snapshot['body'],
                'rating' => $snapshot['rating'],
                'media' => $this->json->serialize($this->mediaNormalizer->normalize($snapshot['media'])),
            ],
            ['heading', 'body', 'rating', 'media']
        );
    }

    /**
     * @return list<array{id: string, thumbnail_url: string}>
     */
    private function decodeMedia(string $media): array
    {
        $decoded = $this->json->unserialize($media);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->mediaNormalizer->normalize($decoded);
    }
}
