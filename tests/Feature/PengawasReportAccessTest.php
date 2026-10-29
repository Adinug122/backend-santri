<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Laporan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PengawasReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

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
            ->assertJsonPath('laporan.menyangkut_pimpinan', false)
            ->assertJsonPath('laporan.terlambat', false);

        $this->assertDatabaseHas('laporans', [
            'users_id' => $santri->id,
            'menyangkut_pimpinan' => true,
        ]);
        $this->assertDatabaseHas('laporans', [
            'users_id' => $santri->id,
            'menyangkut_pimpinan' => false,
        ]);
    }

    public function test_pimpinan_cannot_see_or_act_on_flagged_reports(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $regular = $this->createReport($owner);
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regular->id);

        $this->getJson("/api/reports/{$flagged->id}")
            ->assertForbidden();
        $this->getJson("/api/reports/{$flagged->id}/messages")->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/messages", ['isi' => 'Pesan.'])->assertForbidden();
        $this->patchJson("/api/reports/{$flagged->id}/status", ['status' => 'diproses'])->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/notes", ['catatan' => 'Catatan.'])->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/escalate", ['tujuan' => 'Yayasan'])->assertForbidden();
        $this->postJson("/api/reports/{$flagged->id}/identity-request", ['alasan' => 'Ingin tahu.'])
            ->assertForbidden();

        $this->assertDatabaseHas('laporans', ['id' => $flagged->id, 'status' => 'baru']);
        $this->assertDatabaseHas('laporans', ['id' => $regular->id]);
        $this->assertDatabaseCount('identity_requests', 0);
    }

    public function test_pengurus_cannot_see_flagged_reports_or_restricted_reports(): void
    {
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $regular = $this->createReport($owner);
        $restricted = $this->createReport($owner);
        $restricted->update(['kategori' => 'masalah_pengurus']);
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $regular->id);

        $this->getJson("/api/reports/{$flagged->id}")
            ->assertForbidden();
        $this->patchJson("/api/reports/{$flagged->id}/status", ['status' => 'diproses'])
            ->assertForbidden();
        $this->getJson("/api/reports/{$restricted->id}")->assertForbidden();
        $this->assertDatabaseHas('laporans', ['id' => $regular->id]);
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

    public function test_santri_owner_and_pengawas_see_the_late_field_for_flagged_reports(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $owner = $this->createUser('santri');
        $flagged = $this->createReport($owner, true);
        $this->ageReport($flagged, 25);

        Sanctum::actingAs($owner);
        $this->getJson("/api/reports/{$flagged->id}")
            ->assertOk()
            ->assertJsonPath('data.terlambat', true);
        $this->getJson('/api/reports/mine')
            ->assertOk()
            ->assertJsonPath('data.0.terlambat', true);

        Sanctum::actingAs($this->createUser('pengawas'));
        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('data.0.terlambat', true);
        $this->getJson("/api/reports/{$flagged->id}")
            ->assertOk()
            ->assertJsonPath('data.terlambat', true);
    }

    public function test_late_scope_and_accessor_follow_status_and_24_hour_limit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00'));
        $owner = $this->createUser('santri');
        $report23HoursOld = $this->createReport($owner);
        $reportExactly24HoursOld = $this->createReport($owner);
        $report25HoursOld = $this->createReport($owner);
        $processedReport25HoursOld = $this->createReport($owner);
        $this->ageReport($report23HoursOld, 23);
        Laporan::query()->whereKey($reportExactly24HoursOld->id)->update([
            'created_at' => now()->subHours(24),
        ]);
        $this->ageReport($report25HoursOld, 25);
        $this->ageReport($processedReport25HoursOld, 25);
        $processedReport25HoursOld->update(['status' => 'diproses']);

        $this->assertFalse($report23HoursOld->fresh()->terlambat);
        $this->assertFalse($reportExactly24HoursOld->fresh()->terlambat);
        $this->assertTrue($report25HoursOld->fresh()->terlambat);
        $this->assertFalse($processedReport25HoursOld->fresh()->terlambat);
        $this->assertSame(
            [$report25HoursOld->id],
            Laporan::query()->terlambat()->pluck('id')->all(),
        );
    }

    public function test_report_list_orders_late_reports_first_then_newest(): void
    {
        Carbon::setTestNow('2026-10-28 12:00:00');
        $owner = $this->createUser('santri');
        $newestLateReport = $this->createReport($owner);
        $olderLateReport = $this->createReport($owner);
        $recentReport = $this->createReport($owner);
        $this->ageReport($newestLateReport, 25);
        $this->ageReport($olderLateReport, 26);
        $this->ageReport($recentReport, 1);
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newestLateReport->id)
            ->assertJsonPath('data.1.id', $olderLateReport->id)
            ->assertJsonPath('data.2.id', $recentReport->id);
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

        $this->postJson("/api/petugas/laporan/{$flagged->id}/eskalasi", [
            'tujuan' => 'pengurus_pusat',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Yayasan',
        ])->assertForbidden();

        $this->postJson("/api/reports/{$flagged->id}/identity-request", [
            'alasan' => 'Diperlukan untuk pemeriksaan independen.',
        ])->assertCreated();

        $this->patchJson("/api/reports/{$regular->id}/status", ['status' => 'diproses'])
            ->assertForbidden();

        $this->assertDatabaseHas('laporans', [
            'id' => $flagged->id,
            'status' => 'diproses',
            'is_locked' => false,
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
        Carbon::setTestNow('2026-10-28 12:00:00');
        $owner = $this->createUser('santri');
        $regularLate = $this->createReport($owner);
        $this->createReport($owner);
        $sensitiveLate = $this->createReport($owner, true);
        $this->ageReport($regularLate, 25);
        $this->ageReport($sensitiveLate, 25);

        Sanctum::actingAs($this->createUser('pimpinan'));
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.total_laporan', 2)
            ->assertJsonPath('data.jumlah_terlambat', 1);

        Sanctum::actingAs($this->createUser('pengawas'));
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.total_laporan', 1)
            ->assertJsonPath('data.jumlah_terlambat', 1);

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

    private function ageReport(Laporan $laporan, int $hours): void
    {
        Laporan::query()
            ->whereKey($laporan->id)
            ->update(['created_at' => now()->subHours($hours)]);
    }
}
