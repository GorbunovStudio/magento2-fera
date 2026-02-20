<?php

declare(strict_types=1);

namespace Fera\Ai\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Rating implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, int|string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 1, 'label' => '1'],
            ['value' => 2, 'label' => '2'],
            ['value' => 3, 'label' => '3'],
            ['value' => 4, 'label' => '4'],
            ['value' => 5, 'label' => '5'],
        ];
    }
}
