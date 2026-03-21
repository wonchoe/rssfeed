<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-5-mini'),
        'schema_models' => env('OPENAI_SCHEMA_MODELS', ''),
        'translation_model' => env('OPENAI_TRANSLATION_MODEL', 'gpt-4o-mini'),
    ],

    'xiaomi' => [
        'token' => env('XIAOMI_TOKEN'),
        'base_url' => 'https://api.xiaomimimo.com/v1/chat/completions',
        'model' => env('XIAOMI_MODEL', 'mimo-v2-pro'),
    ],

    'translation' => [
        'provider' => env('TRANSLATION_PROVIDER', 'openai'), // openai | xiaomi
    ],

    'email_digest' => [
        'enabled' => env('EMAIL_DIGEST_ENABLED', false),
        'send_at' => env('EMAIL_DIGEST_SEND_AT', '08:00'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env(
            'GOOGLE_REDIRECT_URI',
            rtrim((string) env('APP_URL', 'http://rss.cursor.style'), '/').'/auth/google/callback'
        ),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME'),
        'default_chat_id' => env('TELEGRAM_DEFAULT_CHAT_ID'),
        'api_base' => env('TELEGRAM_API_BASE', 'https://api.telegram.org'),
        'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        'link_token_ttl_minutes' => env('TELEGRAM_LINK_TOKEN_TTL_MINUTES', 15),
        'chat_request_ttl_minutes' => env('TELEGRAM_CHAT_REQUEST_TTL_MINUTES', 10),
    ],

];
