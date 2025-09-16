<?php

declare(strict_types=1);

namespace Fera\Ai\Services;

interface ResettableDependenciesInterface
{
    public function resetRepositories(): void;
}
