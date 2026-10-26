<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PengawasReportAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_santri_can_create_a_flagged_report_and_regular_reports_default_to_false(): void
    {
        $santri = $this->createUser('santri');
        Sanctum::actingAs($santri);

        $this->postJson('/api/reports', [
            'kategori' => 'Perilaku pimpinan',
            'isi' => 'Laporan yang menyangkut pimpinan pondok.',
            'menyangkut_pimpinan' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('laporan.menyangkut_pimpinan', true);

        $this->postJson('/api/reports', [
            'kategori' => 'Fasilitas',
            'isi' => 'Lampu kamar rusak.',
        ])
            ->assertCreated()
            ->assertJsonPath('laporan.menyangkut_pimpinan', false);

        $this->assertDatabaseHas('laporans', [
            'users_id' => $santri->id,
            'menyangkut_pimpinan' => true,
        ]);
        $this->assertDatabaseHas('laporans', [
            'users_id' => $santri->id,
            'menyangkut_pimpinan' => false,
        ]);
    }

    public function test_pimpinan_cannot_see_or_act_on_reports_about_themselves(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $regular = $this->createReport($owner);
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regular->id);

        $this->getJson("/api/reports/{$flagged->id}")->assertForbidden();
        $this->getJson("/api/reports/{$flagged->id}/messages")->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/messages", ['isi' => 'Pesan.'])->assertForbidden();
        $this->patchJson("/api/reports/{$flagged->id}/status", ['status' => 'diproses'])->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/notes", ['catatan' => 'Catatan.'])->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/escalate", ['tujuan' => 'Yayasan'])->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/identity-request", ['alasan' => 'Ingin tahu.'])->assertForbidden();

        $this->assertDatabaseHas('laporans', [
            'id' => $flagged->id,
            'status' => 'baru',
        ]);
        $this->assertDatabaseCount('identity_requests', 0);
    }

    public function test_pengurus_cannot_see_flagged_reports(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $regular = $this->createReport($owner);
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regular->id);

        $this->getJson("/api/reports/{$flagged->id}")->assertForbidden();
        $this->patchJson("/api/reports/{$flagged->id}/status", ['status' => 'diproses'])->assertForbidden();
    }

    public function test_pengawas_only_lists_and_opens_flagged_reports(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $regular = $this->createReport($owner);
        Sanctum::actingAs($this->createUser('pengawas'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $flagged->id);

        $this->getJson("/api/reports/{$flagged->id}")->assertOk();
        $this->getJson("/api/reports/{$regular->id}")->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLog::REPORT_VIEWED,
            'subject_id' => $flagged->id,
        ]);
    }

    public function test_pengawas_can_handle_flagged_reports_but_not_regular_reports(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $regular = $this->createReport($owner);
        Sanctum::actingAs($this->createUser('pengawas'));

        $this->patchJson("/api/reports/{$flagged->id}/status", ['status' => 'diproses'])
            ->assertOk();

        $this->postJson("/api/reports/{$flagged->id}/messages", ['isi' => 'Kami menindaklanjuti.'])
            ->assertCreated();

        $this->postJson("/api/reports/{$flagged->id}/notes", ['catatan' => 'Diteruskan ke yayasan.'])
            ->assertCreated();

        $this->postJson("/api/reports/{$flagged->id}/escalate", ['tujuan' => 'Yayasan'])
            ->assertOk();

        $this->postJson("/api/reports/{$flagged->id}/identity-request", [
            'alasan' => 'Diperlukan untuk pemeriksaan independen.',
        ])->assertCreated();

        $this->patchJson("/api/reports/{$regular->id}/status", ['status' => 'diproses'])
            ->assertForbidden();

        $this->assertDatabaseHas('laporans', [
            'id' => $flagged->id,
            'status' => 'diproses',
        ]);
        $this->assertDatabaseHas('laporans', [
            'id' => $regular->id,
            'status' => 'baru',
        ]);
    }

    public function test_pengawas_cannot_create_reports_or_use_santri_and_account_endpoints(): void
    {
        $santri = $this->createUser('santri');
        Sanctum::actingAs($this->createUser('pengawas'));

        $this->postJson('/api/reports', [
            'kategori' => 'Fasilitas',
            'isi' => 'Laporan dari pengawas.',
        ])->assertForbidden();

        $this->getJson('/api/reports/mine')->assertForbidden();
        $this->getJson('/api/admin/accounts')->assertForbidden();
        $this->patchJson("/api/admin/accounts/{$santri->id}/status")->assertForbidden();
    }

    public function test_dashboard_summary_is_scoped_to_the_role(): void
    {
        $owner = $this->createUser('santri');
        $this->createReport($owner);
        $this->createReport($owner);
        $this->createReport($owner, true);

        Sanctum::actingAs($this->createUser('pimpinan'));
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.total_laporan', 2);

        Sanctum::actingAs($this->createUser('pengawas'));
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.total_laporan', 1);

        Sanctum::actingAs($this->createUser('pengurus'));
        $this->getJson('/api/dashboard/summary')->assertForbidden();
    }

    public function test_notifications_are_scoped_and_read_state_is_per_role(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $this->createReport($owner);

        Sanctum::actingAs($this->createUser('pimpinan'));
        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1);

        Sanctum::actingAs($this->createUser('pengawas'));
        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1);

        $this->getJson("/api/reports/{$flagged->id}")->assertOk();

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 0);

        Sanctum::actingAs($this->createUser('pimpinan'));
        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1);
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

    private function createReport(User $owner, bool $menyangkutPimpinan = false): Laporan
    {
        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-TEST-'.Str::random(10),
            'kategori' => 'Fasilitas',
            'isi' => 'Isi laporan uji.',
            'tampilkan_identitas' => false,
            'menyangkut_pimpinan' => $menyangkutPimpinan,
            'status' => 'baru',
            'waktu' => now(),
        ]);
    }
}
