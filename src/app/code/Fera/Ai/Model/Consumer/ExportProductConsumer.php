<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Model\Message\ProductExportMessage;
use Psr\Log\LoggerInterface;

class ExportProductConsumer
{
    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductExporter
     */
    private $productExporter;

    /**
     * @var FeraHelper
     */
    private $helper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * ExportProductConsumer constructor.
     *
     * @param ProductRepositoryInterface $productRepository
     * @param ProductExporter $productExporter
     * @param FeraHelper $helper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        ProductExporter $productExporter,
        FeraHelper $helper,
        LoggerInterface $logger
    ) {
        $this->productRepository = $productRepository;
        $this->productExporter = $productExporter;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * Process product export message
     *
     * @param ProductExportMessage $message
     */
    public function process(ProductExportMessage $message): void
    {
        $productId = $message->getProductId();
        $storeId = $message->getStoreId();
        
        try {
            if (!$this->helper->isEnabled($storeId)) {
                return;
            }

            $product = $this->productRepository->getById($productId, false, $storeId);

            $this->productExporter->pushProduct($product, $storeId);

            $this->logger->info("Successfully exported product: {$productId} for store: {$storeId}");
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to export product: {$productId} for store: {$storeId}. Error: {$e->getMessage()}",
                ['exception' => $e]
            );
        }
    }
}
