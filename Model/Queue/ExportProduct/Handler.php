<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Queue\ExportProduct;

use Composer\Platform\Runtime;
use Fera\Ai\Api\Data\Queue\ExportProduct\MessageInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Services\StoreGroupService;
use InvalidArgumentException;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\ProductRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

class Handler
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private ProductExporter $productExporter,
        private FeraHelper $helper,
        private LoggerInterface $logger,
        private StoreGroupService $storeGroupService
    ) {
    }

    public function process(MessageInterface $message): void
    {
        $productId = $message->getProductId();
        $storeId = $message->getStoreId();

        try {
            if ($productId === null || $storeId === null) {
                throw new InvalidArgumentException(
                    "Invalid product export message: productId={$productId}, storeId={$storeId}"
                );
            }

            $mainStoresMap = $this->storeGroupService->getStoresToMainStoresMap();
            if (!isset($mainStoresMap[$storeId])) {
                throw new RuntimeException("Fera is not configured for store: {$storeId}");
            }

            $storeId = $mainStoresMap[$storeId];

            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            $product = $this->productRepository->getById($productId, false, $storeId);

            $this->productExporter->pushProduct($product, $storeId);
        } catch (\Throwable $exception) {
            $this->logger->error(
                $exception->getMessage(),
                ['exception' => $exception, 'trace' => $exception->getTrace()]
            );
            
            throw new \RuntimeException(
                'Unable to process the queue message: ' . $exception->getMessage(),
                $exception->getCode(),
                $exception
            );
        } finally {
            if ($this->productRepository instanceof ProductRepository) {
                // Reset the repository state to avoid stale data issues
                $this->productRepository->_resetState();
            }
        }
    }
}
