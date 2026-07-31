<?php

declare(strict_types=1);

namespace Fera\Ai\Test\Unit;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/**
 * Magento generates these factory classes in the consuming application.
 * Unit tests only need their method shape so PHPUnit can mock them.
 */
if (!class_exists('Fera\\Ai\\Test\\Unit\\StandaloneFactory')) {
    class StandaloneFactory
    {
        public function create(array $data = []): object
        {
            throw new \LogicException('Standalone factory stub must be mocked in a unit test.');
        }
    }
}

foreach ([
    'Fera\\Ai\\Model\\ReviewSnapshotFactory',
    'Fera\\Ai\\Model\\ResourceModel\\ReviewSnapshot\\CollectionFactory',
    'Fera\\Ai\\Api\\Data\\Queue\\NotifyNegativeReview\\MessageInterfaceFactory',
    'Fera\\Ai\\Api\\Data\\Queue\\NotifyPositiveReview\\MessageInterfaceFactory',
    'Fera\\Ai\\Api\\Data\\Queue\\NotifyReviewUpdate\\MessageInterfaceFactory',
    'GuzzleHttp\\ClientFactory',
] as $generatedFactory) {
    if (!class_exists($generatedFactory)) {
        class_alias(StandaloneFactory::class, $generatedFactory);
    }
}
