<?php

namespace App\Support;

class WebhookUrlValidator
{
    public static function errorFor(string $channel, string $url): ?string
    {
        return match ($channel) {
            'slack' => ! str_starts_with($url, 'https://hooks.slack.com/')
                ? 'Slack webhook URLs must start with https://hooks.slack.com/'
                : null,
            'discord' => ! str_starts_with($url, 'https://discord.com/api/webhooks/')
                ? 'Discord webhook URLs must start with https://discord.com/api/webhooks/'
                : null,
            'teams' => ! self::isValidTeamsWebhookUrl($url)
                ? 'Teams webhook URLs must be Power Automate / Power Platform / Office workflow URLs.'
                : null,
            'email' => ! filter_var($url, FILTER_VALIDATE_EMAIL)
                ? 'Please enter a valid email address.'
                : null,
            default => 'Unsupported channel.',
        };
    }

    public static function isValidTeamsWebhookUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (! is_array($parsed) || strtolower((string) ($parsed['scheme'] ?? '')) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parsed['host'] ?? ''));
        $path = (string) ($parsed['path'] ?? '');
        $query = (string) ($parsed['query'] ?? '');

        if ($host === '') {
            return false;
        }

        if (self::hostMatches($host, 'logic.azure.com')) {
            return true;
        }

        if (self::hostMatches($host, 'office.com') || self::hostMatches($host, 'webhook.office.com')) {
            return true;
        }

        if (
            self::hostMatches($host, 'environment.api.powerplatform.com')
            || self::hostMatches($host, 'api.powerplatform.com')
        ) {
            return str_contains($path, '/powerautomate/automations/direct/workflows/')
                && str_contains($query, 'sig=');
        }

        return false;
    }

    private static function hostMatches(string $host, string $expectedHost): bool
    {
        return $host === $expectedHost || str_ends_with($host, '.'.$expectedHost);
    }
}