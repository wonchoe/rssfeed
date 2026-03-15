<?php

return [
    'discovery' => [
        'timeout_seconds' => env('INGESTION_DISCOVERY_TIMEOUT', 15),
        'feed_probe_enabled' => (bool) env('INGESTION_DISCOVERY_FEED_PROBE_ENABLED', true),
        'feed_probe_timeout_seconds' => env('INGESTION_DISCOVERY_FEED_PROBE_TIMEOUT', 8),
        'feed_probe_max_attempts' => env('INGESTION_DISCOVERY_FEED_PROBE_MAX_ATTEMPTS', 6),
        'feed_probe_paths' => [
            '/rss',
            '/rss.xml',
            '/feed',
            '/feed.xml',
            '/atom.xml',
            '/index.xml',
            '/news/rss.xml',
            '/rss/news.xml',
            '/rss/full.rss',
        ],
        'feed_probe_host_map' => [
            'tsn.ua' => [
                'https://tsn.ua/rss/full.rss',
            ],
            'bbc.com' => [
                'https://feeds.bbci.co.uk/news/rss.xml',
            ],
            'bbc.co.uk' => [
                'https://feeds.bbci.co.uk/news/rss.xml',
            ],
            'npr.org' => [
                'https://feeds.npr.org/1001/rss.xml',
            ],
            'cnbc.com' => [
                'https://www.cnbc.com/id/100003114/device/rss/rss.html',
            ],
            'dw.com' => [
                'https://rss.dw.com/xml/rss-en-top',
            ],
            'axios.com' => [
                'https://api.axios.com/feed',
            ],
        ],
    ],

    'fetch' => [
        'timeout_seconds' => env('INGESTION_FETCH_TIMEOUT', 20),
        'retry_times' => env('INGESTION_FETCH_RETRY_TIMES', 2),
        'retry_sleep_ms' => env('INGESTION_FETCH_RETRY_SLEEP_MS', 300),
        'user_agent' => env('INGESTION_USER_AGENT', 'rss.cursor.style-ingestion-bot/1.0'),
        'domain_max_per_minute' => env('INGESTION_FETCH_MAX_PER_DOMAIN_PER_MINUTE', 12),
        'domain_release_seconds' => env('INGESTION_FETCH_DOMAIN_RELEASE_SECONDS', 15),
    ],

    'payload_cache_ttl_minutes' => env('INGESTION_PAYLOAD_CACHE_TTL', 120),
    'parsed_cache_ttl_minutes' => env('INGESTION_PARSED_CACHE_TTL', 60),
    'normalized_cache_ttl_minutes' => env('INGESTION_NORMALIZED_CACHE_TTL', 60),
    'parse_max_items' => env('INGESTION_PARSE_MAX_ITEMS', 100),
    'cached_source_fresh_minutes' => env('INGESTION_CACHED_SOURCE_FRESH_MINUTES', 60),
    'poll_inactive_sources' => (bool) env('INGESTION_POLL_INACTIVE_SOURCES', false),
    'inactive_polling_interval_minutes' => env('INGESTION_INACTIVE_POLLING_INTERVAL_MINUTES', 1440),
    'cold_polling_interval_minutes' => env('INGESTION_COLD_POLLING_INTERVAL_MINUTES', 10080),
    'cold_source_after_days' => env('INGESTION_COLD_SOURCE_AFTER_DAYS', 30),
    'schedule_jitter_seconds' => env('INGESTION_SCHEDULE_JITTER_SECONDS', 120),
    'scheduler_max_per_domain_per_cycle' => env('INGESTION_SCHEDULER_MAX_PER_DOMAIN_PER_CYCLE', 2),
    'scheduler_max_dispatch_per_cycle' => env('INGESTION_SCHEDULER_MAX_DISPATCH_PER_CYCLE', 180),
    'ai_repair_enabled' => (bool) env('INGESTION_AI_REPAIR_ENABLED', true),
    'ai_repair_failure_threshold' => env('INGESTION_AI_REPAIR_FAILURE_THRESHOLD', 3),
    'ai_repair_cooldown_minutes' => env('INGESTION_AI_REPAIR_COOLDOWN_MINUTES', 60),
    'ai_repair_use_openai' => (bool) env('INGESTION_AI_REPAIR_USE_OPENAI', true),
    'ai_preview_min_items' => env('INGESTION_AI_PREVIEW_MIN_ITEMS', 4),
    'schema_shadow_success_runs_to_activate' => env('INGESTION_SCHEMA_SHADOW_SUCCESS_RUNS_TO_ACTIVATE', 2),
    'schema_validation_activate_score' => env('INGESTION_SCHEMA_VALIDATION_ACTIVATE_SCORE', 70),
    'schema_validation_min_items' => env('INGESTION_SCHEMA_VALIDATION_MIN_ITEMS', 3),
    'schema_validation_snapshot_limit' => env('INGESTION_SCHEMA_VALIDATION_SNAPSHOT_LIMIT', 3),
    'capture_snapshots' => (bool) env('INGESTION_CAPTURE_SNAPSHOTS', true),
    'snapshot_max_bytes' => env('INGESTION_SNAPSHOT_MAX_BYTES', 250000),
    'preview_image_enrichment' => [
        'enabled' => (bool) env('INGESTION_PREVIEW_IMAGE_ENRICHMENT_ENABLED', true),
        'max_items' => env('INGESTION_PREVIEW_IMAGE_ENRICHMENT_MAX_ITEMS', 12),
        'timeout_seconds' => env('INGESTION_PREVIEW_IMAGE_ENRICHMENT_TIMEOUT', 6),
        'ttl_minutes' => env('INGESTION_PREVIEW_IMAGE_ENRICHMENT_TTL', 720),
    ],
    'feed_generation_timeout_seconds' => env('FEED_GENERATION_TIMEOUT_SECONDS', 180),
];
