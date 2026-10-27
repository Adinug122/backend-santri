<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiClient
{
    private const GEMINI_MODEL = 'gemini-3.5-flash';

    private const GROQ_MODEL = 'openai/gpt-oss-20b';

    public function askJson(string $systemPrompt, string $userText): ?array
    {
        $response = $this->requestGemini($systemPrompt, [
            ['role' => 'user', 'parts' => [['text' => $userText]]],
        ]);
        $text = $this->geminiText($response);

        if ($text === null) {
            $response = $this->requestGroq($systemPrompt, [
                ['role' => 'user', 'content' => $userText],
            ]);
            $text = $this->groqText($response);
        }

        return $this->decodeJson($text);
    }

    /**
     * @param  array<int, array{role: string, parts: array<int, array{text: string}>}>  $contents
     */
    public function chatJson(string $systemPrompt, array $contents): ?array
    {
        $response = $this->requestGemini($systemPrompt, $contents);

        if ($response?->successful()) {
            return $this->decodeJson($this->geminiText($response));
        }

        if (! $this->isGeminiRateLimited($response)) {
            return null;
        }

        $messages = array_map(fn (array $content) => [
            'role' => $content['role'] === 'model' ? 'assistant' : 'user',
            'content' => $content['parts'][0]['text'],
        ], $contents);
        $text = $this->groqText($this->requestGroq($systemPrompt, $messages));

        return $this->decodeJson($text);
    }

    /**
     * @param  array<int, array{role: string, parts: array<int, array{text: string}>}>  $contents
     */
    private function requestGemini(string $systemPrompt, array $contents): ?Response
    {
        $apiKey = config('services.gemini.key');

        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
                ->timeout(30)
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.self::GEMINI_MODEL.':generateContent',
                    [
                        'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
                        'contents' => $contents,
                        'generationConfig' => ['responseMimeType' => 'application/json'],
                    ],
                );

            if (! $response->successful()) {
                Log::warning('AI provider request failed.', [
                    'provider' => 'gemini',
                    'model' => self::GEMINI_MODEL,
                    'status' => $response->status(),
                    'error_status' => $response->json('error.status'),
                ]);
            }

            return $response;
        } catch (ConnectionException $exception) {
            report($exception);

            return null;
        }
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function requestGroq(string $systemPrompt, array $messages): ?Response
    {
        $apiKey = config('services.groq.key');

        if (! is_string($apiKey) || $apiKey === '') {
            return null;
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => self::GROQ_MODEL,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ...$messages,
                    ],
                    'response_format' => ['type' => 'json_object'],
                ]);

            if (! $response->successful()) {
                Log::warning('AI provider request failed.', [
                    'provider' => 'groq',
                    'model' => self::GROQ_MODEL,
                    'status' => $response->status(),
                    'error_code' => $response->json('error.code'),
                ]);
            }

            return $response;
        } catch (ConnectionException $exception) {
            report($exception);

            return null;
        }
    }

    private function geminiText(?Response $response): ?string
    {
        $text = $response?->successful()
            ? $response->json('candidates.0.content.parts.0.text')
            : null;

        return is_string($text) ? $text : null;
    }

    private function groqText(?Response $response): ?string
    {
        $text = $response?->successful()
            ? $response->json('choices.0.message.content')
            : null;

        return is_string($text) ? $text : null;
    }

    private function decodeJson(?string $text): ?array
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private function isGeminiRateLimited(?Response $response): bool
    {
        return $response?->status() === 429
            || ($response?->status() === 403 && $response->json('error.status') === 'RESOURCE_EXHAUSTED');
    }
}
