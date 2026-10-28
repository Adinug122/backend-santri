<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IdentityRequest;
use App\Models\Laporan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LaporanEskalasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengurus_can_escalate_a_report_and_receive_whatsapp_and_pdf_urls(): void
    {
        $pengurus = $this->createUser('pengurus');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($pengurus);

        $response = $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", [
            'tujuan' => 'polsek',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Mohon ditindaklanjuti segera.',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Laporan berhasil dieskalasi dan dikunci.');

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => Laporan::STATUS_ESCALATED,
            'is_locked' => true,
        ]);
        $this->assertDatabaseHas('catatans', [
            'users_id' => $pengurus->id,
            'laporan_id' => $report->id,
            'catatan' => 'Eskalasi ke Kepolisian Sektor: Mohon ditindaklanjuti segera.',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'actor_id' => $pengurus->id,
            'action' => AuditLog::REPORT_ESCALATED,
            'subject_id' => $report->id,
            'metadata->tujuan' => 'polsek',
            'metadata->nomor_wa_tujuan' => '+6281234567890',
        ]);

        $whatsAppUrl = $response->json('whatsapp_url');
        parse_str((string) parse_url($whatsAppUrl, PHP_URL_QUERY), $query);
        $this->assertSame('https://wa.me/6281234567890', strtok($whatsAppUrl, '?'));
        $this->assertSame(
            'Yth. Kepolisian Sektor, kami menyampaikan laporan nomor '.$report->no_laporan
                .' dari Sahabat Pesantren. Kategori: Fasilitas. Ringkasan: "Lampu kamar rusak.". '
                .'Catatan pengurus: Mohon ditindaklanjuti segera.',
            $query['text'],
        );
        $this->assertSame(
            route('reports.escalation.pdf', ['laporan' => $report->id]),
            $response->json('pdf_download_url'),
        );

        $auditLog = AuditLog::query()
            ->where('action', AuditLog::REPORT_ESCALATED)
            ->where('subject_id', $report->id)
            ->sole();
        $this->assertNotNull($auditLog->created_at);
        $this->assertSame($pengurus->id, $auditLog->actor_id);
    }

    public function test_only_pengurus_can_escalate_a_report(): void
    {
        $report = $this->createReport($this->createUser('santri'));

        foreach (['santri', 'pimpinan', 'pengawas'] as $role) {
            Sanctum::actingAs($this->createUser($role));

            $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", [
                'tujuan' => 'polsek',
                'nomor_wa_tujuan' => '+6281234567890',
                'catatan_pengurus' => 'Perlu ditindaklanjuti.',
            ])->assertForbidden();
        }

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => 'baru',
            'is_locked' => false,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('catatans', 0);
    }

    public function test_pengurus_cannot_escalate_a_report_they_cannot_access(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        $report->update(['menyangkut_pimpinan' => true]);
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", [
            'tujuan' => 'polsek',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Perlu ditindaklanjuti.',
        ])->assertForbidden();

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => 'baru',
            'is_locked' => false,
        ]);
    }

    public function test_escalation_rejects_invalid_destination_and_phone_number(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", [
            'tujuan' => 'tujuan_lain',
            'nomor_wa_tujuan' => '081234567890',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tujuan', 'nomor_wa_tujuan', 'catatan_pengurus']);

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => 'baru',
            'is_locked' => false,
        ]);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_escalation_requires_authentication(): void
    {
        $report = $this->createReport($this->createUser('santri'));

        $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", [
            'tujuan' => 'polsek',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Perlu ditindaklanjuti.',
        ])->assertUnauthorized();
    }

    public function test_an_escalated_report_cannot_be_escalated_again(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pengurus'));
        $payload = [
            'tujuan' => 'uppt_ppa',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Perlu pendampingan.',
        ];

        $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", $payload)->assertOk();
        $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", $payload)
            ->assertConflict()
            ->assertJsonPath('message', 'Laporan telah dikunci dan sudah pernah dieskalasi.');

        $this->assertDatabaseCount('audit_logs', 1);
        $this->assertDatabaseCount('catatans', 1);
    }

    public function test_legacy_escalation_path_uses_the_same_locked_workflow(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pengurus'));

        $this->postJson("/api/reports/{$report->id}/escalate", [
            'tujuan' => 'polsek',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Mohon ditindaklanjuti.',
        ])->assertOk();

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => Laporan::STATUS_ESCALATED,
            'is_locked' => true,
        ]);
    }

    public function test_locked_reports_reject_update_delete_message_and_note_actions(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $pimpinan = $this->createUser('pimpinan');
        IdentityRequest::create([
            'laporan_id' => $report->id,
            'users_id' => $pimpinan->id,
            'alasan' => 'Perlu verifikasi identitas.',
            'status' => 'menunggu',
        ]);
        $report->update([
            'status' => Laporan::STATUS_ESCALATED,
            'is_locked' => true,
        ]);

        Sanctum::actingAs($santri);
        $this->deleteJson("/api/reports/{$report->id}")
            ->assertConflict()
            ->assertJsonPath('message', 'Laporan telah dikunci dan tidak dapat diubah atau dihapus.');
        $this->patchJson("/api/reports/{$report->id}/identity", [
            'tampilkan_identitas' => true,
        ])->assertConflict();
        $this->postJson("/api/reports/{$report->id}/messages", [
            'isi' => 'Pesan baru.',
        ])->assertConflict();
        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'disetujui',
        ])->assertConflict();

        Sanctum::actingAs($this->createUser('pengurus'));
        $this->patchJson("/api/reports/{$report->id}/status", [
            'status' => 'selesai',
        ])->assertConflict();
        $this->postJson("/api/reports/{$report->id}/notes", [
            'catatan' => 'Catatan baru.',
        ])->assertConflict();

        Sanctum::actingAs($pimpinan);
        $this->postJson("/api/reports/{$report->id}/identity-request", [
            'alasan' => 'Permintaan baru.',
        ])->assertConflict();

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'status' => Laporan::STATUS_ESCALATED,
            'is_locked' => true,
        ]);
        $this->assertDatabaseCount('percakapans', 0);
        $this->assertDatabaseCount('catatans', 0);
    }

    public function test_pengurus_can_download_a_pdf_for_an_escalated_report(): void
    {
        $pengurus = $this->createUser('pengurus');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($pengurus);

        $this->postJson("/api/petugas/laporan/{$report->id}/eskalasi", [
            'tujuan' => 'pengurus_pusat',
            'nomor_wa_tujuan' => '+6281234567890',
            'catatan_pengurus' => 'Mohon pemeriksaan.',
        ])->assertOk();

        $response = $this->get("/api/petugas/laporan/{$report->id}/eskalasi/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=surat-pengantar-'.$report->no_laporan.'.pdf');

        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_santri_cannot_download_an_escalation_pdf(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        $report->update([
            'status' => Laporan::STATUS_ESCALATED,
            'is_locked' => true,
        ]);
        Sanctum::actingAs($this->createUser('santri'));

        $this->getJson("/api/petugas/laporan/{$report->id}/eskalasi/pdf")
            ->assertForbidden();
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
        static $sequence = 0;
        $sequence++;

        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-ESC-'.$sequence,
            'kategori' => 'Fasilitas',
            'isi' => 'Lampu kamar rusak.',
            'tampilkan_identitas' => false,
            'status' => 'baru',
            'waktu' => now(),
        ]);
    }
}
