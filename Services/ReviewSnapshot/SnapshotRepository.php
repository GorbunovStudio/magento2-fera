<?php

declare(strict_types=1);

namespace Fera\Ai\Services\ReviewSnapshot;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\Serializer\Json;
use Zend_Db_Expr;

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
                ->from($tableName, [
                    'review_id',
                    'heading',
                    'body',
                    'rating',
                    'media',
                    'magento_store_id',
                    'subject',
                    'external_product_id',
                    'fera_product_id',
                    'product_name',
                    'state',
                    'is_test',
                    'fera_created_at',
                    'fera_updated_at',
                ])
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
            'magento_store_id' => $this->nullableInt($row['magento_store_id'] ?? null),
            'subject' => $this->nullableString($row['subject'] ?? null),
            'external_product_id' => $this->nullableString($row['external_product_id'] ?? null),
            'fera_product_id' => $this->nullableString($row['fera_product_id'] ?? null),
            'product_name' => $this->nullableString($row['product_name'] ?? null),
            'state' => $this->nullableString($row['state'] ?? null),
            'is_test' => $this->nullableBool($row['is_test'] ?? null),
            'fera_created_at' => $this->nullableString($row['fera_created_at'] ?? null),
            'fera_updated_at' => $this->nullableString($row['fera_updated_at'] ?? null),
        ];
    }

    /**
     * @param ReviewSnapshot $snapshot
     */
    public function save(array $snapshot): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $data = [
            'review_id' => $snapshot['review_id'],
            'heading' => $snapshot['heading'],
            'body' => $snapshot['body'],
            'rating' => $snapshot['rating'],
            'media' => $this->json->serialize($this->mediaNormalizer->normalize($snapshot['media'])),
            'magento_store_id' => $snapshot['magento_store_id'],
            'subject' => $snapshot['subject'],
            'external_product_id' => $snapshot['external_product_id'],
            'fera_product_id' => $snapshot['fera_product_id'],
            'product_name' => $snapshot['product_name'],
            'state' => $snapshot['state'],
            'is_test' => $snapshot['is_test'],
            'fera_created_at' => $snapshot['fera_created_at'],
            'fera_updated_at' => $snapshot['fera_updated_at'],
        ];

        $sourceVersionCondition = '(fera_updated_at IS NULL OR '
            . '(VALUES(fera_updated_at) IS NOT NULL AND VALUES(fera_updated_at) >= fera_updated_at))';
        $mutableFields = [
            'heading',
            'body',
            'rating',
            'media',
            'magento_store_id',
            'subject',
            'external_product_id',
            'fera_product_id',
            'product_name',
            'state',
            'is_test',
        ];
        $updates = [];
        foreach ($mutableFields as $field) {
            $updates[$field] = new Zend_Db_Expr(sprintf(
                'IF(%s, VALUES(%s), %s)',
                $sourceVersionCondition,
                $field,
                $field
            ));
        }

        $updates['fera_created_at'] = new Zend_Db_Expr(
            'IF(fera_created_at IS NULL AND VALUES(fera_created_at) IS NOT NULL, '
            . 'VALUES(fera_created_at), fera_created_at)'
        );
        $updates['fera_updated_at'] = new Zend_Db_Expr(
            'IF(fera_updated_at IS NULL OR '
            . '(VALUES(fera_updated_at) IS NOT NULL AND VALUES(fera_updated_at) >= fera_updated_at), '
            . 'VALUES(fera_updated_at), fera_updated_at)'
        );

        return $connection->insertOnDuplicate(
            $tableName,
            $data,
            $updates
        );
    }

    public function countIncomplete(): int
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName(self::TABLE_NAME);
        $select = $connection->select()
            ->from($tableName, ['count' => new Zend_Db_Expr('COUNT(*)')])
            ->where(
                'fera_created_at IS NULL OR magento_store_id IS NULL OR subject IS NULL '
                . "OR (subject = 'product' AND fera_product_id IS NULL AND external_product_id IS NULL)"
            );

        return (int) $connection->fetchOne($select);
    }

    /**
     * @return list<array{id: string, url: string}>
     */
    private function decodeMedia(string $media): array
    {
        $decoded = $this->json->unserialize($media);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->mediaNormalizer->normalize($decoded);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private function nullableBool(mixed $value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        return !in_array((string) $value, ['0', 'false'], true);
    }
}
