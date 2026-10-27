<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ChatControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.groq.key' => 'test-groq-key',
        ]);
    }

    public function test_returns_401_when_request_is_unauthenticated(): void
    {
        Http::preventStrayRequests();

        $response = $this->postJson('/api/chat', ['message' => 'Hai']);

        $response->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_uses_gemini_when_it_returns_a_response(): void
    {
        Sanctum::actingAs(User::factory()->make());
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => '{"reply":"Hai","quick_replies":[],"draft":null,"risk_level":"low"}']]]],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'Hai']);

        $response->assertOk()
            ->assertJsonPath('data.reply', 'Hai');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'generativelanguage.googleapis.com'));
    }

    public function test_falls_back_to_groq_when_gemini_is_rate_limited(): void
    {
        Sanctum::actingAs(User::factory()->make());
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent' => Http::response([
                'error' => ['status' => 'RESOURCE_EXHAUSTED'],
            ], 429),
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"reply":"Saya dengarkan","quick_replies":[],"draft":null,"risk_level":"low"}']],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat', [
            'message' => 'Saya ingin bercerita',
            'history' => [['role' => 'model', 'text' => 'Tentu, silakan.']],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.reply', 'Saya dengarkan');
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request['messages'][1]['role'] === 'assistant'
            && $request['messages'][1]['content'] === 'Tentu, silakan.');
    }

    public function test_falls_back_to_groq_when_gemini_quota_is_exhausted(): void
    {
        Sanctum::actingAs(User::factory()->make());
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent' => Http::response([
                'error' => ['status' => 'RESOURCE_EXHAUSTED'],
            ], 403),
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"reply":"Saya mendengarkan","quick_replies":[],"draft":null,"risk_level":"low"}']],
                ],
            ]),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'Saya ingin bercerita']);

        $response->assertOk()
            ->assertJsonPath('data.reply', 'Saya mendengarkan');
        Http::assertSentCount(2);
    }

    public function test_does_not_use_groq_for_non_rate_limit_gemini_errors(): void
    {
        Sanctum::actingAs(User::factory()->make());
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent' => Http::response([
                'error' => ['status' => 'INTERNAL'],
            ], 500),
        ]);

        $response = $this->postJson('/api/chat', ['message' => 'Hai']);

        $response->assertStatus(500)
            ->assertJsonPath('data', null);
        Http::assertSentCount(1);
    }
}
