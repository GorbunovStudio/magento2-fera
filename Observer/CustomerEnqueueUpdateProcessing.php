<?php

declare(strict_types=1);

namespace Fera\Ai\Observer;

use Fera\Ai\Api\Data\Queue\TopicInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\MessageQueue\PublisherInterface;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

class CustomerEnqueueUpdateProcessing implements ObserverInterface
{
    public function __construct(
        private PublisherInterface $publisher,
        private LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        try {
            $customer = $observer->getEvent()->getCustomer();
            if (!$customer instanceof Customer) {
                throw new UnexpectedValueException(
                    'Incorrect type for Customer: expected ' . Customer::class . ', got ' . get_debug_type($customer)
                );
            }

            $customerId = $customer->getId();
            if (!is_numeric($customerId) || $customer->getOrigData() === null) {
                // New customer - skip
                return;
            }
            
            if (!$this->hasRelevantChanges($customer)) {
                return;
            }

            $customerIdInt = (int) $customerId;
            $this->publisher->publish(TopicInterface::PROCESS_CUSTOMER_UPDATE, $customerIdInt);
            
            $this->logger->debug(
                "Customer {$customerIdInt}: Published customer update message due to name/email changes"
            );
        } catch (\Throwable $exception) {
            $this->logger->error(
                'Failed to publish customer update message: ' . $exception->getMessage(),
                ['exception' => $exception]
            );
            // Do not rethrow to avoid blocking customer save
        }
    }

    private function hasRelevantChanges(Customer $customer): bool
    {
        $originalFirstName = $customer->getOrigData('firstname');
        $originalFirstName = is_string($originalFirstName) ? trim($originalFirstName) : '';

        $currentFirstName = trim((string) $customer->getFirstname());

        if ($originalFirstName !== $currentFirstName) {
            return true;
        }

        $originalLastName = $customer->getOrigData('lastname');
        $originalLastName = is_string($originalLastName) ? trim($originalLastName) : '';
        
        $currentLastName = trim((string) $customer->getLastname());

        if ($originalLastName !== $currentLastName) {
            return true;
        }

        // We don't track email changes because Magento has its own observer,
        // which updates email in all customer's orders which triggers order update
        return false;
    }
}
