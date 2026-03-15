<?php

namespace App\Domain\Parsing\Services;

use App\Domain\Parsing\Contracts\AiSchemaResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AdaptiveAiSchemaResolver implements AiSchemaResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(string $html, string $sourceUrl): array
    {
        $payload = trim($html);

        if ($payload === '') {
            return [
                'valid' => false,
                'strategy' => 'empty_payload',
                'error' => 'Source payload is empty.',
            ];
        }

        if ((bool) config('ingestion.ai_repair_use_openai', true) === true) {
            $openAiSchema = $this->resolveViaOpenAi($payload, $sourceUrl);

            if ($openAiSchema !== null) {
                return $openAiSchema;
            }
        }

        return $this->heuristicSchema($payload, $sourceUrl);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveViaOpenAi(string $html, string $sourceUrl): ?array
    {
        $apiKey = trim((string) config('services.openai.api_key', ''));

        if ($apiKey === '') {
            return null;
        }

        $snippet = Str::limit($html, 32000, '');

        $prompt = [
            'task' => 'Generate robust XPath schema for extracting a news listing page.',
            'source_url' => $sourceUrl,
            'requirements' => [
                'Return valid JSON object only.',
                'Use XPath fields: article_xpath, title_xpath, link_xpath, summary_xpath, image_xpath, date_xpath.',
                'title_xpath/link_xpath/summary_xpath/image_xpath/date_xpath must be relative to one article node.',
                'link_xpath should return @href.',
                'Prefer stable semantic selectors and avoid brittle nth-child chains.',
                'Set valid=false if page is not a news listing.',
                'Include confidence as float between 0 and 1.',
                'Return short reason field.',
            ],
            'expected_output' => [
                'valid' => 'boolean',
                'strategy' => 'ai_generated_xpath',
                'confidence' => 'float 0..1',
                'reason' => 'short string',
                'article_xpath' => 'absolute XPath selecting article containers',
                'title_xpath' => 'relative XPath from article node',
                'link_xpath' => 'relative XPath from article node, usually ending with /@href',
                'summary_xpath' => 'relative XPath or empty string',
                'image_xpath' => 'relative XPath or empty string',
                'date_xpath' => 'relative XPath or empty string',
                'requires_browser' => 'boolean (optional)',
            ],
            'html' => $snippet,
            'output_example' => [
                'valid' => true,
                'strategy' => 'ai_generated_xpath',
                'confidence' => 0.74,
                'reason' => 'Detected repeated article cards with headlines and links.',
                'article_xpath' => '//article[.//a[@href]]',
                'title_xpath' => './/h2|.//h3|.//a[1]',
                'link_xpath' => './/a[@href][1]/@href',
                'summary_xpath' => './/p[1]',
                'image_xpath' => './/img[1]/@src',
                'date_xpath' => './/time/@datetime|.//time',
            ],
        ];

        foreach ($this->resolveModelCandidates() as $model) {
            try {
                $response = Http::timeout(45)
                    ->withToken($apiKey)
                    ->acceptJson()
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $model,
                        'response_format' => [
                            'type' => 'json_object',
                        ],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Return only a JSON object. No markdown, no prose, no code fences.',
                            ],
                            [
                                'role' => 'user',
                                'content' => json_encode($prompt, JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ]);
            } catch (\Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($content) || trim($content) === '') {
                continue;
            }

            $decoded = $this->decodeJsonObject($content);

            if (! is_array($decoded)) {
                continue;
            }

            $decoded['strategy'] = $decoded['strategy'] ?? 'ai_generated_xpath';
            $decoded['model'] = $model;

            return $decoded;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(string $content): ?array
    {
        $raw = trim($content);

        if (Str::startsWith($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
            $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
            $raw = trim($raw);
        }

        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');

        if ($start !== false && $end !== false && $end > $start) {
            $raw = substr($raw, $start, ($end - $start) + 1);
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function heuristicSchema(string $html, string $sourceUrl): array
    {
        $hasArticle = str_contains(Str::lower($html), '<article');
        $hasManyLinks = substr_count(Str::lower($html), '<a ') >= 10;

        return [
            'valid' => $hasArticle || $hasManyLinks,
            'strategy' => 'heuristic_xpath_fallback',
            'source_url' => $sourceUrl,
            'confidence' => $hasArticle ? 0.58 : 0.41,
            'article_xpath' => $hasArticle
                ? '//article[.//a[@href]]'
                : '//main//*[self::article or self::li or self::div][.//a[@href]]',
            'title_xpath' => './/h1|.//h2|.//h3|.//a[1]',
            'link_xpath' => './/a[@href][1]/@href',
            'summary_xpath' => './/p[1]',
            'image_xpath' => './/img[1]/@src',
            'date_xpath' => './/time/@datetime|.//time',
        ];
    }

    /**
     * @return list<string>
     */
    private function resolveModelCandidates(): array
    {
        $raw = trim((string) config('services.openai.schema_models', ''));

        if ($raw === '') {
            $fallback = trim((string) config('services.openai.model', 'gpt-5-mini'));

            return $fallback !== '' ? [$fallback] : ['gpt-5-mini'];
        }

        $models = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', $raw),
        ), static fn (string $value): bool => $value !== ''));

        if ($models === []) {
            $fallback = trim((string) config('services.openai.model', 'gpt-5-mini'));

            return $fallback !== '' ? [$fallback] : ['gpt-5-mini'];
        }

        return array_values(array_unique($models));
    }
}
