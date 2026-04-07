<?php

declare(strict_types=1);

namespace Fera\Ai\Api\Data;

interface ProductAttributeCodeInterface
{
    /**
     * Product attribute code used to override SKU sent to Fera for Google review matching.
     */
    public const FERA_SKU_OVERRIDE = 'fera_sku_override';
}
