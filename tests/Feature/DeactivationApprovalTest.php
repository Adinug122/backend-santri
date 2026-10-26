<?php

namespace Tests\Feature;

use App\Models\AccountDeactivationRequest;
use App\Models\AuditLog;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeactivationApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_cannot_directly_deactivate_an_account_with_open_reports(): void
    {
        $pimpinan = $this->createUser('pimpinan');
        $santri = $this->createUser('santri');
        $token = $santri->createToken('existing-session');
        $report = $this->createReport($santri);
        Sanctum::actingAs($pimpinan);

        $this->patchJson("/api/admin/accounts/{$santri->id}/status")
            ->assertUnprocessable()
            ->assertJsonPath('requires_approval', true);

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);

        $report->update(['status' => 'selesai']);

        $this->patchJson("/api/admin/accounts/{$santri->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_pimpinan_can_request_and_pengawas_can_approve_a_deactivation(): void
    {
        $pimpinan = $this->createUser('pimpinan');
        $pengawas = $this->createUser('pengawas');
        $santri = $this->createUser('santri');
        $token = $santri->createToken('existing-session');
        $this->createReport($santri);

        Sanctum::actingAs($pimpinan);
        $response = $this->postJson("/api/admin/accounts/{$santri->id}/deactivation-requests", [
            'reason' => 'Santri melanggar aturan dan menolak pembinaan.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'menunggu');

        $requestId = $response->json('data.id');

        $this->assertDatabaseHas('account_deactivation_requests', [
            'id' => $requestId,
            'user_id' => $santri->id,
            'requested_by' => $pimpinan->id,
            'status' => 'menunggu',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::DEACTIVATION_REQUESTED,
            'actor_id' => $pimpinan->id,
        ]);

        Sanctum::actingAs($pengawas);

        $this->getJson('/api/admin/deactivation-requests?status=menunggu')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $requestId);

        $this->patchJson("/api/admin/deactivation-requests/{$requestId}", [
            'status' => 'disetujui',
            'catatan' => 'Disetujui setelah pemeriksaan.',
        ])
            ->assertOk()
            ->assertJsonPath('target_is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
        $this->assertDatabaseHas('account_deactivation_requests', [
            'id' => $requestId,
            'status' => 'disetujui',
            'decided_by' => $pengawas->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::DEACTIVATION_APPROVED,
            'actor_id' => $pengawas->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::ACCOUNT_DEACTIVATED,
            'subject_id' => $santri->id,
        ]);
    }

    public function test_pengawas_can_reject_a_deactivation_and_account_stays_active(): void
    {
        $pimpinan = $this->createUser('pimpinan');
        $pengawas = $this->createUser('pengawas');
        $santri = $this->createUser('santri');
        $this->createReport($santri);
        $request = $this->createDeactivationRequest($santri, $pimpinan);

        Sanctum::actingAs($pengawas);

        $this->patchJson("/api/admin/deactivation-requests/{$request->id}", [
            'status' => 'ditolak',
        ])
            ->assertOk()
            ->assertJsonPath('target_is_active', true);

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('account_deactivation_requests', [
            'id' => $request->id,
            'status' => 'ditolak',
            'decided_by' => $pengawas->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::DEACTIVATION_REJECTED,
            'actor_id' => $pengawas->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', [
            'action' => AuditLog::ACCOUNT_DEACTIVATED,
            'subject_id' => $santri->id,
        ]);
    }

    public function test_pimpinan_cannot_request_deactivation_without_open_reports(): void
    {
        $santri = $this->createUser('santri');
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->postJson("/api/admin/accounts/{$santri->id}/deactivation-requests", [
            'reason' => 'Alasan apa pun.',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('account_deactivation_requests', 0);
    }

    public function test_deactivation_request_requires_a_reason(): void
    {
        $santri = $this->createUser('santri');
        $this->createReport($santri);
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->postJson("/api/admin/accounts/{$santri->id}/deactivation-requests", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
    }

    public function test_duplicate_pending_deactivation_requests_are_rejected(): void
    {
        $pimpinan = $this->createUser('pimpinan');
        $santri = $this->createUser('santri');
        $this->createReport($santri);
        Sanctum::actingAs($pimpinan);

        $payload = ['reason' => 'Alasan penonaktifan.'];

        $this->postJson("/api/admin/accounts/{$santri->id}/deactivation-requests", $payload)
            ->assertCreated();
        $this->postJson("/api/admin/accounts/{$santri->id}/deactivation-requests", $payload)
            ->assertStatus(409);

        $this->assertDatabaseCount('account_deactivation_requests', 1);
    }

    public function test_role_boundaries_for_deactivation_endpoints(): void
    {
        $pimpinan = $this->createUser('pimpinan');
        $santri = $this->createUser('santri');
        $this->createReport($santri);
        $request = $this->createDeactivationRequest($santri, $pimpinan);

        Sanctum::actingAs($this->createUser('pengawas'));
        $this->postJson("/api/admin/accounts/{$santri->id}/deactivation-requests", [
            'reason' => 'Pengawas tidak boleh mengajukan.',
        ])->assertForbidden();

        Sanctum::actingAs($pimpinan);
        $this->patchJson("/api/admin/deactivation-requests/{$request->id}", [
            'status' => 'disetujui',
        ])->assertForbidden();

        Sanctum::actingAs($this->createUser('santri'));
        $this->getJson('/api/admin/deactivation-requests')->assertForbidden();
    }

    public function test_pimpinan_cannot_deactivate_a_pengawas_account(): void
    {
        $pengawas = $this->createUser('pengawas');
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->patchJson("/api/admin/accounts/{$pengawas->id}/status")
            ->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $pengawas->id,
            'is_active' => true,
        ]);
    }

    public function test_a_decided_request_cannot_be_decided_again(): void
    {
        $pimpinan = $this->createUser('pimpinan');
        $santri = $this->createUser('santri');
        $this->createReport($santri);
        $request = $this->createDeactivationRequest($santri, $pimpinan);
        Sanctum::actingAs($this->createUser('pengawas'));

        $this->patchJson("/api/admin/deactivation-requests/{$request->id}", [
            'status' => 'ditolak',
        ])->assertOk();

        $this->patchJson("/api/admin/deactivation-requests/{$request->id}", [
            'status' => 'disetujui',
        ])->assertStatus(409);

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'is_active' => true,
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

    private function createReport(User $owner): Laporan
    {
        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-TEST-'.Str::random(10),
            'kategori' => 'Fasilitas',
            'isi' => 'Isi laporan uji.',
            'tampilkan_identitas' => false,
            'menyangkut_pimpinan' => false,
            'status' => 'baru',
            'waktu' => now(),
        ]);
    }

    private function createDeactivationRequest(User $target, User $requester): AccountDeactivationRequest
    {
        return AccountDeactivationRequest::create([
            'user_id' => $target->id,
            'requested_by' => $requester->id,
            'reason' => 'Alasan penonaktifan.',
            'status' => 'menunggu',
        ]);
    }
}
