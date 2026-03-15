<?php

return [
    'allowed_emails' => array_values(array_filter(array_map(
        static fn (string $value): string => strtolower(trim($value)),
        explode(',', (string) env('ADMIN_ALLOWED_EMAILS', ''))
    ))),

    'allow_all_in_local' => (bool) env('ADMIN_ALLOW_ALL_LOCAL', true),
];
