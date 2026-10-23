<?php

namespace Tests\Feature;

use App\Models\IdentityRequest;
use App\Models\Laporan;
use App\Models\Percakapan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_santri_receives_identity_request_and_new_message_notifications_until_reading_messages(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $pimpinan = $this->createUser('pimpinan');
        IdentityRequest::create([
            'laporan_id' => $report->id,
            'users_id' => $pimpinan->id,
            'alasan' => 'Diperlukan untuk tindak lanjut.',
            'bukti_path' => 'identity-requests/bukti.png',
            'status' => 'menunggu',
        ]);
        Percakapan::create([
            'isi' => 'Mohon lengkapi informasi laporan.',
            'pengirim_id' => $pimpinan->id,
            'laporan_id' => $report->id,
        ]);
        Sanctum::actingAs($santri);

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.permintaan_identitas', 1)
            ->assertJsonPath('data.pesan_baru', 1)
            ->assertJsonPath('data.status_diubah', 0)
            ->assertJsonPath('data.pembaruan', 2);

        $this->getJson("/api/reports/{$report->id}/messages")->assertOk();

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.permintaan_identitas', 0)
            ->assertJsonPath('data.pesan_baru', 0)
            ->assertJsonPath('data.pembaruan', 0);
    }

    public function test_pimpinan_and_pengurus_receive_new_report_and_message_notifications_until_reading_messages(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Percakapan::create([
            'isi' => 'Ada informasi tambahan.',
            'pengirim_id' => $report->users_id,
            'laporan_id' => $report->id,
        ]);
        $pimpinan = $this->createUser('pimpinan');
        $pengurus = $this->createUser('pengurus');

        Sanctum::actingAs($pimpinan);
        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1)
            ->assertJsonPath('data.pesan_baru', 1);

        Sanctum::actingAs($pengurus);
        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1)
            ->assertJsonPath('data.pesan_baru', 1);

        $this->getJson("/api/reports/{$report->id}/messages")->assertOk();

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 0)
            ->assertJsonPath('data.pesan_baru', 0);
    }

    public function test_unauthenticated_user_cannot_read_notification_summary(): void
    {
        $this->getJson('/api/notifications/summary')->assertUnauthorized();
    }

    public function test_each_role_marks_read_with_its_own_column_when_reading_report_messages(): void
    {
        $report = $this->createReport($this->createUser('santri'));

        Sanctum::actingAs($this->createUser('pengurus'));
        $this->getJson("/api/reports/{$report->id}/messages")->assertOk();

        $this->assertNotNull($report->fresh()->pengurus_dibaca);
        $this->assertNull($report->fresh()->pimpinan_dibaca);
        $this->assertNull($report->fresh()->santri_dibaca);

        Sanctum::actingAs($this->createUser('pimpinan'));
        $this->getJson("/api/reports/{$report->id}/messages")->assertOk();

        $this->assertNotNull($report->fresh()->pimpinan_dibaca);
        $this->assertNull($report->fresh()->santri_dibaca);
    }

    public function test_each_role_marks_read_with_its_own_column_when_opening_report_detail(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);

        Sanctum::actingAs($this->createUser('pimpinan'));
        $this->getJson("/api/reports/{$report->id}")->assertOk();

        $this->assertNotNull($report->fresh()->pimpinan_dibaca);
        $this->assertNull($report->fresh()->pengurus_dibaca);

        Sanctum::actingAs($santri);
        $this->getJson("/api/reports/{$report->id}")->assertOk();

        $this->assertNotNull($report->fresh()->santri_dibaca);
    }

    public function test_read_column_mapping_covers_every_user_role(): void
    {
        $this->assertSame('santri_dibaca', Laporan::readColumnFor('santri'));
        $this->assertSame('pengurus_dibaca', Laporan::readColumnFor('pengurus'));
        $this->assertSame('pimpinan_dibaca', Laporan::readColumnFor('pimpinan'));
        $this->assertNull(Laporan::readColumnFor('perawat'));
    }

    public function test_summary_returns_object_with_santri_keys(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $pimpinan = $this->createUser('pimpinan');

        IdentityRequest::create([
            'laporan_id' => $report->id,
            'users_id' => $pimpinan->id,
            'alasan' => 'Diperlukan untuk tindak lanjut.',
            'bukti_path' => 'identity-requests/bukti.png',
            'status' => 'menunggu',
        ]);

        Percakapan::create([
            'isi' => 'Mohon lengkapi informasi laporan.',
            'pengirim_id' => $pimpinan->id,
            'laporan_id' => $report->id,
        ]);

        Sanctum::actingAs($santri);

        $data = $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->json('data');

        $this->assertIsArray($data);
        $this->assertFalse(array_is_list($data));
        $this->assertSame(
            ['permintaan_identitas', 'pesan_baru', 'status_diubah', 'pembaruan'],
            array_keys($data),
        );
        $this->assertSame(1, $data['permintaan_identitas']);
        $this->assertSame(1, $data['pesan_baru']);
        $this->assertSame(0, $data['status_diubah']);
        $this->assertSame(2, $data['pembaruan']);
    }

    public function test_summary_returns_object_with_staff_keys(): void
    {
        $this->createReport($this->createUser('santri'));

        foreach (['pengurus', 'pimpinan'] as $role) {
            Sanctum::actingAs($this->createUser($role));

            $data = $this->getJson('/api/notifications/summary')
                ->assertOk()
                ->json('data');

            $this->assertIsArray($data);
            $this->assertFalse(array_is_list($data));
            $this->assertSame(
                ['laporan_baru', 'pesan_baru', 'pembaruan'],
                array_keys($data),
            );
            $this->assertSame(1, $data['laporan_baru']);
            $this->assertSame(0, $data['pesan_baru']);
            $this->assertSame(1, $data['pembaruan']);
        }
    }

    public function test_unauthenticated_summary_returns_json_401_without_accept_header(): void
    {
        $this->get('/api/notifications/summary')
            ->assertStatus(401)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_every_role_marks_its_own_read_column_on_detail_and_messages(): void
    {
        foreach (['santri', 'pengurus', 'pimpinan'] as $role) {
            $user = $this->createUser($role);
            $report = $this->createReport($role === 'santri' ? $user : $this->createUser('santri'));

            Sanctum::actingAs($user);

            $this->getJson("/api/reports/{$report->id}")->assertOk();

            $this->assertReadColumnFilledFor($report->fresh(), $role);
        }

        foreach (['santri', 'pengurus', 'pimpinan'] as $role) {
            $user = $this->createUser($role);
            $report = $this->createReport($role === 'santri' ? $user : $this->createUser('santri'));

            Sanctum::actingAs($user);

            $this->getJson("/api/reports/{$report->id}/messages")->assertOk();

            $this->assertReadColumnFilledFor($report->fresh(), $role);
        }
    }

    public function test_pimpinan_counts_all_new_reports_visible_to_staff(): void
    {
        $this->createReport($this->createUser('santri'));
        $this->createReport($this->createUser('santri'));

        $closed = $this->createReport($this->createUser('santri'));
        $closed->update(['status' => 'selesai']);

        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 2)
            ->assertJsonPath('data.pembaruan', 2);
    }

    public function test_pembaruan_counts_each_reason_per_report_for_santri(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $pimpinan = $this->createUser('pimpinan');

        IdentityRequest::create([
            'laporan_id' => $report->id,
            'users_id' => $pimpinan->id,
            'alasan' => 'Diperlukan untuk tindak lanjut.',
            'bukti_path' => 'identity-requests/bukti.png',
            'status' => 'menunggu',
        ]);

        Percakapan::create([
            'isi' => 'Mohon lengkapi informasi laporan.',
            'pengirim_id' => $pimpinan->id,
            'laporan_id' => $report->id,
        ]);

        Laporan::whereKey($report->id)->update(['created_at' => now()->subMinute()]);
        $report->update(['status' => 'diproses', 'status_diubah' => now()]);

        Sanctum::actingAs($santri);

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.permintaan_identitas', 1)
            ->assertJsonPath('data.pesan_baru', 1)
            ->assertJsonPath('data.status_diubah', 1)
            ->assertJsonPath('data.pembaruan', 3);
    }

    public function test_pembaruan_counts_each_reason_per_report_for_staff(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);

        Percakapan::create([
            'isi' => 'Ada informasi tambahan.',
            'pengirim_id' => $santri->id,
            'laporan_id' => $report->id,
        ]);

        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1)
            ->assertJsonPath('data.pesan_baru', 1)
            ->assertJsonPath('data.pembaruan', 2);
    }

    private function assertReadColumnFilledFor(Laporan $report, string $role): void
    {
        $column = Laporan::readColumnFor($role);

        $this->assertNotNull($column);

        if ($column === null) {
            return;
        }

        $this->assertNotNull($report->{$column}, "Kolom {$column} belum terisi untuk role {$role}.");

        foreach (array_diff(array_values(Laporan::READ_COLUMNS), [$column]) as $otherColumn) {
            $this->assertNull($report->{$otherColumn}, "Kolom {$otherColumn} tidak boleh terisi untuk role {$role}.");
        }
    }

    private function createUser(string $role): User
    {
        static $sequence = 0;
        $sequence++;

        return User::create([
            'name' => ucfirst($role).' Test',
            'email' => $role.'.notification.'.$sequence.'@example.test',
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
            'no_laporan' => 'LAP-NOTIF-'.$sequence,
            'kategori' => 'Fasilitas',
            'isi' => 'Lampu kamar rusak.',
            'tampilkan_identitas' => false,
            'status' => 'baru',
            'waktu' => now(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
