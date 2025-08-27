<?php

namespace Fera\Ai\Observer\Frontend;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Fera\Ai\Helper\Data as FeraHelper;
use Fera\Ai\Services\ProductExporter;

class ProductPushEvent implements ObserverInterface
{
    protected $helper;
    protected $productExporter;

    /**
     * Product view constructor.
     * @param FeraHelper $helper
     * @param ProductExporter $productExporter
     */
    public function __construct(
        FeraHelper $helper,
        ProductExporter $productExporter
    ) {
        $this->helper = $helper;
        $this->productExporter = $productExporter;
    }

    /**
     * Use curl to push products to our server.
     *
     * @param Observer $observer
     */
    public function execute(Observer $observer)
    {
        if (!$this->helper->isEnabled()) {
            return;
        }

        $p = $observer->getProduct();
        $this->productExporter->pushProduct($p);
    }
}
