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

    public function test_notification_list_returns_one_item_per_category_per_report_for_santri(): void
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

        $response = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('code', 'NOTIFICATION_LIST_SUCCESS')
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 20)
            ->assertJsonPath('data.meta.total', 2)
            ->assertJsonPath('data.meta.last_page', 1)
            ->assertJsonPath('data.meta.from', 1)
            ->assertJsonPath('data.meta.to', 2);

        $items = collect($response->json('data.items'))->keyBy('tipe');

        $this->assertSame(['permintaan_identitas', 'pesan_baru'], $items->keys()->sort()->values()->all());

        $identity = $items['permintaan_identitas'];
        $this->assertSame($report->id * 10 + 4, $identity['id']);
        $this->assertSame($report->id, $identity['laporan_id']);
        $this->assertSame($report->no_laporan, $identity['no_laporan']);
        $this->assertSame($report->kategori, $identity['kategori']);
        $this->assertSame('Diperlukan untuk tindak lanjut.', $identity['preview']);
        $this->assertSame(['id' => $pimpinan->id, 'name' => $pimpinan->name, 'role' => 'pimpinan'], $identity['pengirim']);
        $this->assertNull($identity['status_baru']);
        $this->assertSame("/api/reports/{$report->id}", $identity['url']);

        $message = $items['pesan_baru'];
        $this->assertSame($report->id * 10 + 2, $message['id']);
        $this->assertSame('Mohon lengkapi informasi laporan.', $message['preview']);
        $this->assertSame($pimpinan->id, $message['pengirim']['id']);
        $this->assertNull($message['status_baru']);

        $this->assertNull($report->fresh()->santri_dibaca);
    }

    public function test_notification_list_masks_hidden_santri_identity_for_staff(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $report->update(['tampilkan_identitas' => false]);

        Sanctum::actingAs($this->createUser('pengurus'));

        $response = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        $item = $response->json('data.items.0');

        $this->assertSame('laporan_baru', $item['tipe']);
        $this->assertSame($report->id, $item['laporan_id']);
        $this->assertNull($item['pengirim']);
        $this->assertSame('Lampu kamar rusak.', $item['preview']);
        $this->assertSame('baru', $item['status_baru']);

        $this->assertNull($report->fresh()->pengurus_dibaca);
    }

    public function test_notification_list_shows_identity_when_report_is_not_hidden(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $report->update(['tampilkan_identitas' => true]);

        Sanctum::actingAs($this->createUser('pimpinan'));

        $item = $this->getJson('/api/notifications')
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame([
            'id' => $santri->id,
            'name' => $santri->name,
            'role' => 'santri',
        ], $item['pengirim']);
    }

    public function test_notification_list_is_sorted_by_latest_event(): void
    {
        $santri = $this->createUser('santri');
        $older = $this->createReport($santri);
        $newer = $this->createReport($santri);

        Laporan::whereKey($older->id)->update(['created_at' => now()->subMinutes(5)]);
        Laporan::whereKey($newer->id)->update(['created_at' => now()->subMinute()]);

        Sanctum::actingAs($this->createUser('pimpinan'));

        $response = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.laporan_id', $newer->id)
            ->assertJsonPath('data.items.1.laporan_id', $older->id);

        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertSame($ids->unique()->count(), $ids->count());
    }

    public function test_notification_list_supports_pagination(): void
    {
        $santri = $this->createUser('santri');

        foreach (range(1, 3) as $index) {
            $report = $this->createReport($santri);
            Laporan::whereKey($report->id)->update(['created_at' => now()->subMinutes(10 - $index)]);
        }

        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->getJson('/api/notifications?page=1&per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.meta.current_page', 1)
            ->assertJsonPath('data.meta.per_page', 2)
            ->assertJsonPath('data.meta.total', 3)
            ->assertJsonPath('data.meta.last_page', 2)
            ->assertJsonPath('data.meta.from', 1)
            ->assertJsonPath('data.meta.to', 2);

        $this->getJson('/api/notifications?page=2&per_page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.meta.current_page', 2)
            ->assertJsonPath('data.meta.from', 3)
            ->assertJsonPath('data.meta.to', 3);
    }

    public function test_notification_list_and_summary_stay_in_sync_after_reading_report(): void
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
            ->assertJsonPath('data.pembaruan', 2);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);

        $this->getJson("/api/reports/{$report->id}")->assertOk();

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.pembaruan', 0);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0)
            ->assertJsonCount(0, 'data.items');
    }

    public function test_pengurus_does_not_receive_masalah_pengurus_notifications(): void
    {
        $owner = $this->createUser('santri');
        $restricted = $this->createReport($owner, 'masalah_pengurus');
        $visible = $this->createReport($owner);
        $staff = $this->createUser('pengurus');

        Percakapan::create([
            'isi' => 'Pesan pada laporan terbatas.',
            'pengirim_id' => $staff->id,
            'laporan_id' => $restricted->id,
        ]);
        Percakapan::create([
            'isi' => 'Pesan pada laporan biasa.',
            'pengirim_id' => $staff->id,
            'laporan_id' => $visible->id,
        ]);

        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertJsonPath('data.laporan_baru', 1)
            ->assertJsonPath('data.pesan_baru', 1);

        $items = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->json('data.items');

        $this->assertNotContains($restricted->id, array_column($items, 'laporan_id'));
        $this->assertContains($visible->id, array_column($items, 'laporan_id'));
    }

    public function test_status_change_notification_exposes_the_new_status(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);

        Laporan::whereKey($report->id)->update(['created_at' => now()->subMinutes(5)]);
        $report->update(['status' => 'diproses', 'status_diubah' => now()]);

        Sanctum::actingAs($santri);

        $item = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->json('data.items.0');

        $this->assertSame('status_diubah', $item['tipe']);
        $this->assertSame($report->id * 10 + 3, $item['id']);
        $this->assertSame('diproses', $item['status_baru']);
        $this->assertNull($item['pengirim']);
    }

    public function test_notification_summary_supports_etag(): void
    {
        $this->createReport($this->createUser('santri'));

        Sanctum::actingAs($this->createUser('pimpinan'));

        $response = $this->getJson('/api/notifications/summary')
            ->assertOk()
            ->assertHeader('ETag');

        $etag = $response->headers->get('ETag');

        $this->withHeader('If-None-Match', $etag)
            ->getJson('/api/notifications/summary')
            ->assertStatus(304);
    }

    public function test_unauthenticated_user_cannot_read_notification_list(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
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

    private function createReport(User $owner, string $category = 'Fasilitas'): Laporan
    {
        static $sequence = 0;
        $sequence++;

        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-NOTIF-'.$sequence,
            'kategori' => $category,
            'isi' => 'Lampu kamar rusak.',
            'tampilkan_identitas' => false,
            'status' => 'baru',
            'waktu' => now(),
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);
    }
}
