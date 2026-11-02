<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Services;

use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Vendor\LocaleJsonMerger\Contracts\LocaleTranslator;

final class OpenAiLocaleTranslator implements LocaleTranslator
{
    public function translate(string $value, string $targetLanguage, string $path = ''): string
    {
        $apiKey = (string) $this->setting('api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('An OpenAI API key is required for translation. Set OPENAI_API_KEY.');
        }

        $http = Facade::getFacadeApplication() !== null ? Http::getFacadeRoot() : new Factory();
        $response = $http->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) $this->setting('timeout', 30))
            ->post((string) $this->setting('endpoint', 'https://api.openai.com/v1/chat/completions'), [
                'model' => (string) $this->setting('model', 'gpt-4o'),
                'temperature' => 0,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Translate the supplied locale string into the requested target language. Preserve placeholders, HTML tags, and formatting. Return only the translated string.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'target_language' => $targetLanguage,
                            'key' => $path,
                            'text' => $value,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI translation failed: '.$response->body());
        }

        $translated = $response->json('choices.0.message.content');

        if (! is_string($translated) || trim($translated) === '') {
            throw new RuntimeException('OpenAI returned an empty translation.');
        }

        return trim($translated);
    }

    /**
     * @param  list<array{id: int, path: string, text: string}>  $items
     * @return array<int, string>
     */
    public function translateBatch(array $items, string $targetLanguage): array
    {
        if ($items === []) {
            return [];
        }

        $apiKey = (string) $this->setting('api_key', '');

        if ($apiKey === '') {
            throw new RuntimeException('An OpenAI API key is required for translation. Set OPENAI_API_KEY.');
        }

        $http = Facade::getFacadeApplication() !== null ? Http::getFacadeRoot() : new Factory();
        $response = $http->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) $this->setting('timeout', 30))
            ->post((string) $this->setting('endpoint', 'https://api.openai.com/v1/chat/completions'), [
                'model' => (string) $this->setting('model', 'gpt-4o'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Translate every supplied locale string into the target language. Preserve placeholders, HTML tags, and formatting. Return a JSON object whose keys are the supplied numeric ids and whose values are only the translated strings.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'target_language' => $targetLanguage,
                            'items' => $items,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    ],
                ],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI translation failed: '.$response->body());
        }

        $content = $response->json('choices.0.message.content');
        $translations = is_string($content) ? json_decode($content, true) : null;

        if (! is_array($translations)) {
            throw new RuntimeException('OpenAI returned an invalid batch translation.');
        }

        $result = [];

        foreach ($items as $item) {
            $translated = $translations[(string) $item['id']] ?? null;

            if (! is_string($translated) || trim($translated) === '') {
                throw new RuntimeException('OpenAI returned an incomplete batch translation.');
            }

            $result[$item['id']] = trim($translated);
        }

        return $result;
    }

    private function setting(string $key, mixed $default): mixed
    {
        if (function_exists('app')) {
            try {
                $application = app();

                if ($application->bound('config')) {
                    return $application->make('config')->get('locale-json-merger.openai.'.$key, $default);
                }
            } catch (\Throwable) {
                // Fall back to environment variables in the standalone demo.
            }
        }

        $environmentKeys = [
            'api_key' => 'OPENAI_API_KEY',
            'model' => 'OPENAI_TRANSLATION_MODEL',
            'endpoint' => 'OPENAI_API_ENDPOINT',
            'timeout' => 'OPENAI_TRANSLATION_TIMEOUT',
        ];
        $environmentKey = $environmentKeys[$key] ?? '';
        $value = $_ENV[$environmentKey] ?? $_SERVER[$environmentKey] ?? getenv($environmentKey);

        return $value === false || $value === '' ? $default : $value;
    }
}