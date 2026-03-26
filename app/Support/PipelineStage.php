<?php

namespace App\Support;

enum PipelineStage: string
{
    case GeneratePreview = 'generate_preview';
    case SourceDiscovery = 'source_discovery';
    case Fetch = 'fetch';
    case Parse = 'parse';
    case Normalize = 'normalize';
    case Deduplicate = 'deduplicate';
    case Enrich = 'enrich';
    case Cache = 'cache';
    case Deliver = 'deliver';
}
