<?php

namespace App\Data\Source;

readonly class SourceDiscoveryResultData
{
    /**
     * @param  array<int, SourceCandidateData>  $candidates
     * @param  array<int, string>  $warnings
     */
    public function __construct(
        public string $requestedUrl,
        public ?SourceCandidateData $primaryCandidate,
        public array $candidates = [],
        public array $warnings = [],
    ) {}
}
