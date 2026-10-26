<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewing_a_report_writes_an_audit_entry_for_staff(): void
    {
        $pengurus = $this->createUser('pengurus');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($pengurus);

        $this->getJson("/api/reports/{$report->id}")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $pengurus->id,
            'action' => AuditLog::REPORT_VIEWED,
            'subject_type' => $report->getMorphClass(),
            'subject_id' => $report->id,
        ]);
    }

    public function test_sensitive_report_actions_are_audited(): void
    {
        $pengurus = $this->createUser('pengurus');
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        Sanctum::actingAs($pengurus);

        $this->patchJson("/api/reports/{$report->id}/status", ['status' => 'diproses'])->assertOk();
        $this->postJson("/api/reports/{$report->id}/notes", ['catatan' => 'Catatan internal.'])->assertCreated();
        $this->postJson("/api/reports/{$report->id}/escalate", ['tujuan' => 'Yayasan'])->assertOk();
        $this->postJson("/api/reports/{$report->id}/messages", ['isi' => 'Pesan petugas.'])->assertCreated();

        $statusLog = AuditLog::query()->where('action', AuditLog::REPORT_STATUS_CHANGED)->sole();
        $this->assertSame(['from' => 'baru', 'to' => 'diproses'], $statusLog->metadata);

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $pengurus->id,
            'action' => AuditLog::REPORT_NOTE_ADDED,
            'subject_id' => $report->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $pengurus->id,
            'action' => AuditLog::REPORT_ESCALATED,
            'subject_id' => $report->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $pengurus->id,
            'action' => AuditLog::REPORT_MESSAGE_SENT,
            'subject_id' => $report->id,
        ]);
    }

    public function test_identity_request_and_response_are_audited(): void
    {
        $santri = $this->createUser('santri');
        $pimpinan = $this->createUser('pimpinan');
        $report = $this->createReport($santri);

        Sanctum::actingAs($pimpinan);
        $this->postJson("/api/reports/{$report->id}/identity-request", [
            'alasan' => 'Pemeriksaan lanjutan.',
        ])->assertCreated();

        Sanctum::actingAs($santri);
        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'disetujui',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $pimpinan->id,
            'action' => AuditLog::IDENTITY_REQUESTED,
            'subject_id' => $report->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $santri->id,
            'action' => AuditLog::IDENTITY_RESPONDED,
            'subject_id' => $report->id,
        ]);
    }

    public function test_only_pengawas_can_read_audit_logs(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pengurus'));
        $this->getJson("/api/reports/{$report->id}")->assertOk();

        foreach (['pimpinan', 'pengurus', 'santri'] as $role) {
            Sanctum::actingAs($this->createUser($role));
            $this->getJson('/api/admin/audit-logs')->assertForbidden();
        }

        Sanctum::actingAs($this->createUser('pengawas'));

        $this->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonPath('data.0.action', AuditLog::REPORT_VIEWED)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'action', 'subject_type', 'subject_id', 'metadata', 'created_at', 'actor' => ['id', 'name', 'role']],
                ],
                'meta' => ['current_page', 'per_page', 'total', 'last_page'],
            ]);
    }

    public function test_audit_logs_can_be_filtered_by_action_and_actor(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        $pengurus = $this->createUser('pengurus');

        Sanctum::actingAs($pengurus);
        $this->getJson("/api/reports/{$report->id}")->assertOk();
        $this->patchJson("/api/reports/{$report->id}/status", ['status' => 'diproses'])->assertOk();

        Sanctum::actingAs($this->createUser('pengawas'));

        $this->getJson('/api/admin/audit-logs?action='.AuditLog::REPORT_VIEWED)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', AuditLog::REPORT_VIEWED);

        $this->getJson('/api/admin/audit-logs?actor_id='.$pengurus->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_audit_logs_cannot_be_modified_through_the_api(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pengurus'));
        $this->getJson("/api/reports/{$report->id}")->assertOk();

        $log = AuditLog::query()->sole();
        Sanctum::actingAs($this->createUser('pengawas'));

        $this->putJson("/api/admin/audit-logs/{$log->id}", ['action' => 'diubah'])->assertNotFound();
        $this->patchJson("/api/admin/audit-logs/{$log->id}", ['action' => 'diubah'])->assertNotFound();
        $this->deleteJson("/api/admin/audit-logs/{$log->id}")->assertNotFound();

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => AuditLog::REPORT_VIEWED,
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
}
