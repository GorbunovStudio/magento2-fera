<?php

namespace Fera\Ai\Model\Message;

use Magento\Framework\ObjectManagerInterface;
use Fera\Ai\Api\Data\OrderExportMessageDataInterface;

class OrderExportMessageDataFactory
{
    /** @var ObjectManagerInterface */
    private $objectManager;

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create new OrderExportMessageData instance
     * 
     * @param array $data
     * @return OrderExportMessageDataInterface
     */
    public function create(array $data = []): OrderExportMessageDataInterface
    {
        /** @var OrderExportMessageData $message */
        $message = $this->objectManager->create(OrderExportMessageData::class);
        
        if (isset($data['order_id'])) {
            $message->setOrderId((int)$data['order_id']);
        }
        
        return $message;
    }
}
