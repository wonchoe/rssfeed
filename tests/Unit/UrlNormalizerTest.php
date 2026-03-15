<?php

namespace Tests\Unit;

use App\Support\UrlNormalizer;
use PHPUnit\Framework\TestCase;

class UrlNormalizerTest extends TestCase
{
    public function test_normalize_strips_tracking_params_and_fragment(): void
    {
        $normalized = UrlNormalizer::normalize('HTTPS://GitHub.blog:443/?utm_source=test&gclid=123&x=1#top');

        $this->assertSame('https://github.blog/?x=1', $normalized);
    }

    public function test_normalize_keeps_non_tracking_query_parameters(): void
    {
        $normalized = UrlNormalizer::normalize('github.blog/path/?page=2&sort=desc&utm_medium=email');

        $this->assertSame('https://github.blog/path?page=2&sort=desc', $normalized);
    }

    public function test_validates_http_and_https_only(): void
    {
        $this->assertTrue(UrlNormalizer::isValidHttpUrl('https://github.blog/'));
        $this->assertTrue(UrlNormalizer::isValidHttpUrl('http://example.com/news'));
        $this->assertFalse(UrlNormalizer::isValidHttpUrl('ftp://example.com/file'));
        $this->assertFalse(UrlNormalizer::isValidHttpUrl('not-a-url'));
    }
}
