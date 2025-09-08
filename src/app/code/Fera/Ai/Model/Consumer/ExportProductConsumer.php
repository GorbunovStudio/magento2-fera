<?php

namespace Fera\Ai\Model\Consumer;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Fera\Ai\Services\ProductExporter;
use Fera\Ai\Helper\Data as FeraHelper;
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
     * @param int $productId
     */
    public function process(int $productId): void
    {
        try {
            if (!$this->helper->isEnabled()) {
                return;
            }

            $product = $this->productRepository->getById($productId);

            $this->productExporter->pushProduct($product);

            $this->logger->info("Successfully exported product: {$productId}");
        } catch (\Throwable $e) {
            $this->logger->error(
                "Failed to export product: {$productId}. Error: {$e->getMessage()}",
                ['exception' => $e]
            );
        }
    }
}
