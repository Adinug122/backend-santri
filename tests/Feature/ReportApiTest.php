<?php

namespace Tests\Feature;

use App\Models\Catatan;
use App\Models\Laporan;
use App\Models\Percakapan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_update_a_report_status(): void
    {
        $staff = $this->createUser('pengurus');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($staff);

        $this->patchJson("/api/reports/{$report->id}/status", [
            'status' => 'diproses',
        ])
            ->assertOk()
            ->assertJsonPath('laporan.status', 'diproses');

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => 'diproses',
        ]);
    }

    public function test_staff_can_escalate_a_report(): void
    {
        $staff = $this->createUser('pengurus');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($staff);

        $this->postJson("/api/reports/{$report->id}/escalate", [
            'tujuan' => 'Bagian keamanan',
            'keterangan' => 'Perlu ditindaklanjuti',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Eskalasi berhasil dicatat');

        $this->assertDatabaseHas('catatans', [
            'users_id' => $staff->id,
            'laporan_id' => $report->id,
            'catatan' => 'Eskalasi ke Bagian keamanan: Perlu ditindaklanjuti',
        ]);
        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => 'diproses',
        ]);
    }

    public function test_staff_can_read_messages_for_a_report(): void
    {
        $staff = $this->createUser('pimpinan');
        $report = $this->createReport($this->createUser('santri'));
        $message = Percakapan::create([
            'isi' => 'Kami sedang menindaklanjuti laporan.',
            'pengirim_id' => $staff->id,
            'laporan_id' => $report->id,
        ]);
        Sanctum::actingAs($staff);

        $this->getJson("/api/reports/{$report->id}/messages")
            ->assertOk()
            ->assertJsonPath('data.0.id', $message->id)
            ->assertJsonPath('data.0.isi', 'Kami sedang menindaklanjuti laporan.');
    }

    public function test_staff_can_send_a_message_to_a_report(): void
    {
        $staff = $this->createUser('pengurus');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($staff);

        $this->postJson("/api/reports/{$report->id}/messages", [
            'isi' => 'Laporan sedang diproses.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.isi', 'Laporan sedang diproses.');

        $this->assertDatabaseHas('percakapans', [
            'isi' => 'Laporan sedang diproses.',
            'pengirim_id' => $staff->id,
            'laporan_id' => $report->id,
        ]);
    }

    public function test_staff_can_add_an_internal_note_to_a_report(): void
    {
        $staff = $this->createUser('pimpinan');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($staff);

        $this->postJson("/api/reports/{$report->id}/notes", [
            'catatan' => 'Sudah dikonfirmasi dengan pengurus asrama.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.catatan', 'Sudah dikonfirmasi dengan pengurus asrama.');

        $this->assertDatabaseHas('catatans', [
            'catatan' => 'Sudah dikonfirmasi dengan pengurus asrama.',
            'users_id' => $staff->id,
            'laporan_id' => $report->id,
        ]);
    }

    public function test_santri_can_create_a_report_but_staff_cannot(): void
    {
        $santri = $this->createUser('santri');
        Sanctum::actingAs($santri);

        $response = $this->postJson('/api/reports', [
            'kategori' => 'Fasilitas',
            'isi' => 'Lampu kamar rusak.',
            'tampilkan_identitas' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('laporan.users_id', $santri->id);

        $reportId = $response->json('laporan.id');
        $this->assertDatabaseHas('laporans', [
            'id' => $reportId,
            'users_id' => $santri->id,
        ]);

        Sanctum::actingAs($this->createUser('pengurus'));

        $this->postJson('/api/reports', [
            'kategori' => 'Fasilitas',
            'isi' => 'Pengurus tidak boleh membuat laporan santri.',
        ])->assertForbidden();
    }

    public function test_staff_report_list_and_detail_mask_anonymous_report_identity(): void
    {
        $owner = $this->createUser('santri');
        $report = $this->createReport($owner);
        Percakapan::create([
            'isi' => 'Pesan dari pemilik laporan.',
            'pengirim_id' => $owner->id,
            'laporan_id' => $report->id,
        ]);
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('data.0.user.name', 'Anonymous')
            ->assertJsonMissingPath('data.0.user.email')
            ->assertJsonPath('data.0.percakapans.0.pengirim.name', 'Anonymous');

        $this->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Anonymous')
            ->assertJsonMissingPath('data.user.email')
            ->assertJsonPath('data.percakapans.0.pengirim.name', 'Anonymous');
    }

    public function test_santri_report_details_do_not_include_internal_notes(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        Catatan::create([
            'catatan' => 'Catatan internal staff.',
            'users_id' => $this->createUser('pengurus')->id,
            'laporan_id' => $report->id,
        ]);
        Sanctum::actingAs($santri);

        $this->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.catatans');

        $this->getJson('/api/reports/mine')
            ->assertOk()
            ->assertJsonMissingPath('data.0.catatans');
    }

    public function test_staff_cannot_use_santri_report_list_or_identity_toggle(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports/mine')->assertForbidden();

        $this->patchJson("/api/reports/{$report->id}/identity")
            ->assertForbidden();

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'tampilkan_identitas' => false,
        ]);
    }

    public function test_santri_cannot_use_staff_only_report_actions(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        Sanctum::actingAs($santri);

        $this->patchJson("/api/reports/{$report->id}/status", [
            'status' => 'selesai',
        ])->assertForbidden();

        $this->postJson("/api/reports/{$report->id}/escalate", [
            'tujuan' => 'Bagian keamanan',
        ])->assertForbidden();

        $this->postJson("/api/reports/{$report->id}/notes", [
            'catatan' => 'Tidak seharusnya bisa menambahkan catatan internal.',
        ])->assertForbidden();

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => 'baru',
        ]);
        $this->assertDatabaseMissing('catatans', [
            'laporan_id' => $report->id,
        ]);
    }

    public function test_report_messages_require_authentication(): void
    {
        $report = $this->createReport($this->createUser('santri'));

        $this->getJson("/api/reports/{$report->id}/messages")
            ->assertUnauthorized();
    }

    public function test_report_routes_reject_non_numeric_report_identifiers(): void
    {
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports/LAP-TEST-001')
            ->assertNotFound();
    }

    private function createUser(string $role): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'name' => ucfirst($role).' Test',
            'email' => "{$role}{$sequence}@example.test",
            'role' => $role,
            'password' => 'password123',
            'is_active' => true,
        ]);
    }

    private function createReport(User $owner): Laporan
    {
        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-TEST-'.uniqid(),
            'kategori' => 'Fasilitas',
            'isi' => 'Lampu kamar rusak.',
            'tampilkan_identitas' => false,
            'status' => 'baru',
            'waktu' => now(),
        ]);
    }
}
