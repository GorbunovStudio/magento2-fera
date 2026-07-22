<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

use Fera\Ai\Helper\Data;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\Group;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use UnexpectedValueException;

class StoreGroupService
{
    public function __construct(
        private StoreManagerInterface $storeManager,
        private Data $helper
    ) {
    }

    /**
     * Get enabled stores grouped by secret key
     *
     * Groups stores by their Fera secret key and selects a primary store for each group.
     * The primary store is determined by the following hierarchy:
     * 1. Default website goes first
     * 2. Website sort order (ascending, with website ID tie-breaker)
     * 3. Default store group goes first
     * 4. Store group sort order (ascending, with group ID tie-breaker)
     * 5. Default store goes first
     * 6. Store sort order (ascending, with store ID tie-breaker)
     * 7. Store ID (ascending, final tie-breaker)
     *
     * @return int[] Array where keys are primary store IDs and values are arrays of all
     * @phpstan-return array<int, int[]> Array where keys are primary store IDs and values are arrays of all
     *                           store IDs sharing the same secret key
     */
    public function getByFeraAccount(): array
    {
        $storesBySecretKey = [];
        foreach ($this->storeManager->getStores(false) as $store) {
            $storeId = (int)$store->getId();
            if (!$this->helper->isEnabled($storeId)) {
                continue;
            }

            $secretKey = trim($this->helper->getSecretKey($storeId) ?? '');
            if (empty($secretKey)) {
                continue;
            }

            if (!$store instanceof Store) {
                throw new UnexpectedValueException(
                    'Expected instance of ' . Store::class . ', got ' . get_debug_type($store)
                );
            }

            $storesBySecretKey[$secretKey][] = $store;
        }

        $result = [];
        foreach ($storesBySecretKey as $storesInGroup) {
            usort($storesInGroup, function (Store $a, Store $b) {
                $aWebsite = $a->getWebsite();
                $bWebsite = $b->getWebsite();
                $aGroup = $a->getGroup();
                $bGroup = $b->getGroup();

                $aWebsiteIsDefault = $bWebsiteIsDefault = false;
                $aWebsiteSortOrder = $bWebsiteSortOrder = PHP_INT_MAX;
                $aWebsiteDefaultGroupId = $bWebsiteDefaultGroupId = null;

                if ($aWebsite instanceof Website) {
                    $aWebsiteIsDefault = $aWebsite->getIsDefault();
                    $aWebsiteSortOrder = (int) $aWebsite->getSortOrder();
                    $aWebsiteDefaultGroupId = $aWebsite->getDefaultGroupId();
                }

                if ($bWebsite instanceof Website) {
                    $bWebsiteIsDefault = $bWebsite->getIsDefault();
                    $bWebsiteSortOrder = (int) $bWebsite->getSortOrder();
                    $bWebsiteDefaultGroupId = $bWebsite->getDefaultGroupId();
                }

                $aGroupSortOrder = $bGroupSortOrder = PHP_INT_MAX;
                $aGroupDefaultStoreId = $bGroupDefaultStoreId = null;

                if ($aGroup instanceof Group) {
                    $aGroupSortOrder = (int) $aGroup->getSortOrder();
                    $aGroupDefaultStoreId = $aGroup->getDefaultStoreId();
                }

                if ($bGroup instanceof Group) {
                    $bGroupSortOrder = (int) $bGroup->getSortOrder();
                    $bGroupDefaultStoreId = $bGroup->getDefaultStoreId();
                }

                return [
                    (int)!$aWebsiteIsDefault,
                    $aWebsiteSortOrder,
                    (int)$a->getWebsiteId(),
                    (int)!($a->getStoreGroupId() === $aWebsiteDefaultGroupId),
                    $aGroupSortOrder,
                    (int)$a->getStoreGroupId(),
                    (int)!($a->getId() === $aGroupDefaultStoreId),
                    (int)$a->getSortOrder(),
                    (int)$a->getId(),
                ] <=> [
                    (int)!$bWebsiteIsDefault,
                    $bWebsiteSortOrder,
                    (int)$b->getWebsiteId(),
                    (int)!($b->getStoreGroupId() === $bWebsiteDefaultGroupId),
                    $bGroupSortOrder,
                    (int)$b->getStoreGroupId(),
                    (int)!($b->getId() === $bGroupDefaultStoreId),
                    (int)$b->getSortOrder(),
                    (int)$b->getId(),
                ];
            });

            $primaryStoreId = (int)$storesInGroup[0]->getId();
            $storeIds = array_map(static fn(StoreInterface $store) => (int)$store->getId(), $storesInGroup);

            $result[$primaryStoreId] = $storeIds;
        }

        return $result;
    }

    /**
     * @return int[]
     * @phpstan-return array<int, int>
     */
    public function getStoresToMainStoresMap() : array
    {
        $storesGroups = $this->getByFeraAccount();
        $storesToMainStores = [];
        foreach ($storesGroups as $mainStoreId => $storeIds) {
            foreach ($storeIds as $storeId) {
                $storesToMainStores[$storeId] = $mainStoreId;
            }
        }
        return $storesToMainStores;
    }

    public function getCanonicalStoreId(int $storeId): int
    {
        return $this->getStoresToMainStoresMap()[$storeId] ?? $storeId;
    }
}
