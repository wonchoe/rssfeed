<?php

namespace Tests\Unit;

use App\Domain\Parsing\Services\AdaptiveAiSchemaResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdaptiveAiSchemaResolverTest extends TestCase
{
    public function test_resolver_uses_configured_openai_key_and_model_list(): void
    {
        config()->set('ingestion.ai_repair_use_openai', true);
        config()->set('services.openai.api_key', 'test-key');
        config()->set('services.openai.model', 'gpt-5-mini');
        config()->set('services.openai.schema_models', 'gpt-5,gpt-5-mini');

        Http::fake([
            'https://api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'valid' => true,
                                'strategy' => 'ai_generated_xpath',
                                'confidence' => 0.91,
                                'article_xpath' => '//article',
                                'title_xpath' => './/h2',
                                'link_xpath' => './/a/@href',
                            ], JSON_UNESCAPED_SLASHES),
                        ],
                    ],
                ],
            ], 200),
        ]);

        $resolver = new AdaptiveAiSchemaResolver;
        $result = $resolver->resolve('<html><body><article><h2>News</h2><a href="/a">Read</a></article></body></html>', 'https://example.com');

        $this->assertTrue((bool) ($result['valid'] ?? false));
        $this->assertSame('gpt-5', $result['model'] ?? null);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && data_get($request->data(), 'model') === 'gpt-5';
        });
    }
}
