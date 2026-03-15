<?php

namespace App\Domain\Source\Contracts;

use App\Data\Source\SourceDiscoveryResultData;

interface SourceDiscoveryService
{
    public function discover(string $sourceUrl): SourceDiscoveryResultData;
}
