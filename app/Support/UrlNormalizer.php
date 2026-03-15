<?php

namespace App\Support;

use Illuminate\Support\Str;

class UrlNormalizer
{
    /**
     * @var list<string>
     */
    private const TRACKING_QUERY_KEYS = [
        'fbclid',
        'gclid',
        'dclid',
        'msclkid',
        'mc_cid',
        'mc_eid',
        'igshid',
        'ysclid',
        '_ga',
        '_gl',
    ];

    public static function normalize(string $url): string
    {
        $value = trim($url);

        if ($value === '') {
            return $value;
        }

        if (! preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $value)) {
            $value = 'https://'.$value;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['host'])) {
            return $value;
        }

        $scheme = Str::lower($parts['scheme'] ?? 'https');
        $host = rtrim(Str::lower((string) $parts['host']), '.');
        $port = self::normalizePort($scheme, $parts['port'] ?? null);

        $path = $parts['path'] ?? '/';
        $path = preg_replace('~/+~', '/', $path) ?: '/';
        $path = $path === '/' ? '/' : rtrim($path, '/');
        $path = $path === '' ? '/' : $path;

        $query = self::normalizeQuery($parts['query'] ?? null);

        return $scheme.'://'.$host.$port.$path.$query;
    }

    public static function isValidHttpUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        $scheme = Str::lower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        return in_array($scheme, ['http', 'https'], true) && $host !== '';
    }

    public static function absolute(string $url, string $baseUrl): string
    {
        $candidate = trim($url);

        if ($candidate === '') {
            return self::normalize($baseUrl);
        }

        if (preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $candidate)) {
            return self::normalize($candidate);
        }

        $baseParts = parse_url(self::normalize($baseUrl));

        if ($baseParts === false || ! isset($baseParts['host'])) {
            return self::normalize($candidate);
        }

        $scheme = $baseParts['scheme'] ?? 'https';
        $authority = $scheme.'://'.$baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        if (Str::startsWith($candidate, '//')) {
            return self::normalize($scheme.':'.$candidate);
        }

        if (Str::startsWith($candidate, '/')) {
            return self::normalize($authority.$candidate);
        }

        $basePath = $baseParts['path'] ?? '/';
        $directory = str_contains($basePath, '/')
            ? substr($basePath, 0, strrpos($basePath, '/') + 1)
            : '/';

        $resolvedPath = self::resolvePath($directory.$candidate);

        return self::normalize($authority.$resolvedPath);
    }

    private static function normalizePort(string $scheme, mixed $rawPort): string
    {
        if (! is_int($rawPort) && ! ctype_digit((string) $rawPort)) {
            return '';
        }

        $port = (int) $rawPort;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            return '';
        }

        return ':'.$port;
    }

    private static function normalizeQuery(?string $query): string
    {
        if ($query === null || trim($query) === '') {
            return '';
        }

        parse_str($query, $params);

        if (! is_array($params) || $params === []) {
            return '';
        }

        $filtered = [];

        foreach ($params as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (self::isTrackingQueryParam($key)) {
                continue;
            }

            $filtered[$key] = $value;
        }

        if ($filtered === []) {
            return '';
        }

        ksort($filtered);
        $rebuilt = http_build_query($filtered, '', '&', PHP_QUERY_RFC3986);

        return $rebuilt !== '' ? '?'.$rebuilt : '';
    }

    private static function isTrackingQueryParam(string $key): bool
    {
        $normalized = Str::lower(trim($key));

        if ($normalized === '') {
            return false;
        }

        if (Str::startsWith($normalized, 'utm_')) {
            return true;
        }

        return in_array($normalized, self::TRACKING_QUERY_KEYS, true);
    }

    private static function resolvePath(string $path): string
    {
        $segments = explode('/', $path);
        $resolved = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        return '/'.implode('/', $resolved);
    }
}
