<?php

namespace Tests\Feature;

use App\Models\Laporan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardAiSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.key' => 'test-gemini-key',
            'services.groq.key' => 'test-groq-key',
        ]);
    }

    public function test_santri_cannot_request_the_summary(): void
    {
        Http::preventStrayRequests();
        Sanctum::actingAs($this->createUser('santri'));

        $this->postJson('/api/dashboard/ai-summary')->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_pengurus_summary_excludes_restricted_reports_and_private_identity_data(): void
    {
        $this->fakeAiResponse();
        $owner = $this->createUser('santri');
        $owner->name = 'Nama Pelapor Rahasia';
        $owner->save();
        $this->createReport($owner, 'LAPORAN-BIASA');
        $this->createReport($owner, 'RAHASIA-PENGURUS', 'masalah_pengurus');
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->postJson('/api/dashboard/ai-summary')
            ->assertOk()
            ->assertJsonPath('data.ringkasan', 'Ringkasan contoh.')
            ->assertJsonPath('data.jumlah_laporan', 1)
            ->assertJsonPath('data.dari_cache', false)
            ->assertJsonMissingPath('data.menyangkut_pimpinan');

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'LAPORAN-BIASA')
            && ! str_contains($request->body(), 'RAHASIA-PENGURUS')
            && ! str_contains($request->body(), 'Nama Pelapor Rahasia')
            && ! str_contains($request->body(), 'menyangkut_pimpinan'));
    }

    public function test_summary_hides_the_flag_and_uses_a_role_scoped_cache(): void
    {
        $this->fakeAiResponse();
        Cache::forget('dashboard-ai:pimpinan:7');
        $this->createReport($this->createUser('santri'), 'LAPORAN-BERTANDA', 'Insiden', true);
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->postJson('/api/dashboard/ai-summary')
            ->assertOk()
            ->assertJsonPath('data.jumlah_laporan', 1)
            ->assertJsonPath('data.dari_cache', false)
            ->assertJsonMissingPath('data.menyangkut_pimpinan');
        $this->postJson('/api/dashboard/ai-summary')
            ->assertOk()
            ->assertJsonPath('data.dari_cache', true);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'LAPORAN-BERTANDA')
            && ! str_contains($request->body(), 'menyangkut_pimpinan'));
    }

    public function test_pengawas_summary_contains_only_flagged_reports(): void
    {
        $this->fakeAiResponse();
        $owner = $this->createUser('santri');
        $this->createReport($owner, 'LAPORAN-UMUM');
        $this->createReport($owner, 'LAPORAN-PENGAWAS', 'Insiden', true);
        Sanctum::actingAs($this->createUser('pengawas'));

        $this->postJson('/api/dashboard/ai-summary')
            ->assertOk()
            ->assertJsonPath('data.jumlah_laporan', 1);

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), 'LAPORAN-PENGAWAS')
            && ! str_contains($request->body(), 'LAPORAN-UMUM'));
    }

    public function test_report_content_is_limited_to_300_characters_before_sending_to_ai(): void
    {
        $this->fakeAiResponse();
        $content = str_repeat('x', 305);
        $this->createReport($this->createUser('santri'), $content);
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->postJson('/api/dashboard/ai-summary')->assertOk();

        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), str_repeat('x', 300))
            && ! str_contains($request->body(), str_repeat('x', 301)));
    }

    public function test_dashboard_summary_falls_back_to_groq_when_gemini_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([], 500),
            'https://api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'ringkasan' => 'Ringkasan dari cadangan.',
                            'poin' => [],
                        ]),
                    ],
                ]],
            ]),
        ]);
        $this->createReport($this->createUser('santri'), 'LAPORAN');
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->postJson('/api/dashboard/ai-summary')
            ->assertOk()
            ->assertJsonPath('data.ringkasan', 'Ringkasan dari cadangan.');

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request['model'] === 'openai/gpt-oss-20b');
    }

    public function test_returns_503_when_ai_providers_are_unavailable(): void
    {
        Http::preventStrayRequests();
        config([
            'services.gemini.key' => null,
            'services.groq.key' => null,
        ]);
        $this->createReport($this->createUser('santri'), 'LAPORAN');
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->postJson('/api/dashboard/ai-summary')->assertStatus(503);

        Http::assertNothingSent();
    }

    private function fakeAiResponse(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'ringkasan' => 'Ringkasan contoh.',
                            'poin' => ['Poin satu'],
                        ]),
                    ]]],
                ]],
            ]),
        ]);
    }

    private function createUser(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => $role.'.'.Str::random(8).'@example.test',
            'role' => $role,
            'password' => 'password123',
            'is_active' => true,
        ]);
    }

    private function createReport(
        User $owner,
        string $content,
        string $category = 'Fasilitas',
        bool $flagged = false,
    ): Laporan {
        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-TEST-'.Str::random(10),
            'kategori' => $category,
            'isi' => $content,
            'tampilkan_identitas' => false,
            'menyangkut_pimpinan' => $flagged,
            'status' => 'baru',
            'waktu' => now(),
        ]);
    }
}
