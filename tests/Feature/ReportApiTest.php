<?php

namespace Tests\Feature;

use App\Models\Catatan;
use App\Models\IdentityRequest;
use App\Models\Laporan;
use App\Models\Percakapan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_pimpinan_can_submit_an_identity_request_with_evidence(): void
    {
        Storage::fake('local');
        $pimpinan = $this->createUser('pimpinan');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($pimpinan);

        $response = $this->post(
            "/api/reports/{$report->id}/identity-request",
            [
                'alasan' => 'Diperlukan untuk pemeriksaan lanjutan.',
                'bukti' => UploadedFile::fake()->image('bukti.png'),
            ],
            ['Accept' => 'application/json'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'Permintaan identitas berhasil diajukan')
            ->assertJsonPath('data.laporan_id', $report->id)
            ->assertJsonPath('data.users_id', $pimpinan->id)
            ->assertJsonPath('data.status', 'menunggu')
            ->assertJsonPath('data.alasan', 'Diperlukan untuk pemeriksaan lanjutan.')
            ->assertJsonPath('laporan.user.name', 'Anonymous')
            ->assertJsonPath('laporan.permintaan_identitas.status', 'menunggu');

        $identityRequest = IdentityRequest::query()->sole();
        $this->assertDatabaseHas('identity_requests', [
            'id' => $identityRequest->id,
            'laporan_id' => $report->id,
            'users_id' => $pimpinan->id,
            'alasan' => 'Diperlukan untuk pemeriksaan lanjutan.',
        ]);
        Storage::disk('local')->assertExists($identityRequest->bukti_path);
    }

    public function test_santri_can_approve_an_identity_request_and_report_exposes_the_decision(): void
    {
        Storage::fake('local');
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $this->submitIdentityRequest($report, $this->createUser('pimpinan'));
        Sanctum::actingAs($santri);

        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'disetujui',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Permintaan identitas disetujui')
            ->assertJsonStructure([
                'data' => [
                    'user' => ['name'],
                    'permintaan_identitas' => [
                        'status',
                        'alasan',
                        'bukti_url',
                        'waktu',
                    ],
                ],
            ])
            ->assertJsonPath('data.user.name', $santri->name)
            ->assertJsonPath('data.permintaan_identitas.status', 'disetujui')
            ->assertJsonPath('data.permintaan_identitas.alasan', 'Diperlukan untuk pemeriksaan lanjutan.');

        $this->assertDatabaseHas('identity_requests', [
            'laporan_id' => $report->id,
            'status' => 'disetujui',
        ]);
        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'tampilkan_identitas' => true,
        ]);

        $reportResponse = $this->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('data.user.name', $santri->name)
            ->assertJsonPath('data.permintaan_identitas.status', 'disetujui');

        $evidenceUrl = $reportResponse->json('data.permintaan_identitas.bukti_url');
        $this->assertIsString($evidenceUrl);
        $this->assertStringContainsString('identity-requests/', $evidenceUrl);
    }

    public function test_santri_can_reject_an_identity_request_without_revealing_identity(): void
    {
        Storage::fake('local');
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        $this->submitIdentityRequest($report, $this->createUser('pimpinan'));
        Sanctum::actingAs($santri);

        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'ditolak',
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Permintaan identitas ditolak')
            ->assertJsonPath('data.permintaan_identitas.status', 'ditolak');

        $this->assertDatabaseHas('identity_requests', [
            'laporan_id' => $report->id,
            'status' => 'ditolak',
        ]);
        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'tampilkan_identitas' => false,
        ]);
    }

    public function test_identity_request_response_accepts_numeric_status_values_and_stores_table_statuses(): void
    {
        Storage::fake('local');
        $santri = $this->createUser('santri');
        $approvedReport = $this->createReport($santri);
        $rejectedReport = $this->createReport($santri);
        $pimpinan = $this->createUser('pimpinan');
        $this->submitIdentityRequest($approvedReport, $pimpinan);
        $this->submitIdentityRequest($rejectedReport, $pimpinan);
        Sanctum::actingAs($santri);

        $this->patchJson("/api/reports/{$approvedReport->id}/identity-request", [
            'status' => '1',
        ])
            ->assertOk()
            ->assertJsonPath('data.permintaan_identitas.status', 'disetujui');

        $this->patchJson("/api/reports/{$rejectedReport->id}/identity-request", [
            'status' => '0',
        ])
            ->assertOk()
            ->assertJsonPath('data.permintaan_identitas.status', 'ditolak');

        $this->assertDatabaseHas('identity_requests', [
            'laporan_id' => $approvedReport->id,
            'status' => 'disetujui',
        ]);
        $this->assertDatabaseHas('identity_requests', [
            'laporan_id' => $rejectedReport->id,
            'status' => 'ditolak',
        ]);
    }

    public static function usersWhoCannotRespondToIdentityRequests(): array
    {
        return [
            'different santri' => ['santri'],
            'pengurus' => ['pengurus'],
            'pimpinan' => ['pimpinan'],
        ];
    }

    #[DataProvider('usersWhoCannotRespondToIdentityRequests')]
    public function test_only_the_report_owner_can_respond_to_an_identity_request(string $role): void
    {
        Storage::fake('local');
        $report = $this->createReport($this->createUser('santri'));
        $this->submitIdentityRequest($report, $this->createUser('pimpinan'));
        Sanctum::actingAs($this->createUser($role));

        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'disetujui',
        ])->assertForbidden();

        $this->assertDatabaseHas('identity_requests', [
            'laporan_id' => $report->id,
            'status' => 'menunggu',
        ]);
    }

    public function test_identity_request_response_requires_authentication(): void
    {
        $report = $this->createReport($this->createUser('santri'));

        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'disetujui',
        ])->assertUnauthorized();
    }

    public function test_identity_request_response_rejects_invalid_status_values(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        Sanctum::actingAs($santri);

        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'pending',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_identity_request_response_returns_404_when_no_request_is_pending(): void
    {
        $santri = $this->createUser('santri');
        $report = $this->createReport($santri);
        Sanctum::actingAs($santri);

        $this->patchJson("/api/reports/{$report->id}/identity-request", [
            'status' => 'disetujui',
        ])->assertNotFound();
    }

    public static function rolesWithoutIdentityRequestAccess(): array
    {
        return [
            'pengurus' => ['pengurus'],
            'santri' => ['santri'],
        ];
    }

    #[DataProvider('rolesWithoutIdentityRequestAccess')]
    public function test_non_pimpinan_roles_receive_403_when_submitting_identity_requests(string $role): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser($role));

        $this->postJson("/api/reports/{$report->id}/identity-request", [
            'alasan' => 'Meminta akses identitas.',
        ])->assertForbidden();

        $this->assertDatabaseCount('identity_requests', 0);
    }

    public function test_identity_request_requires_authentication(): void
    {
        $report = $this->createReport($this->createUser('santri'));

        $this->postJson("/api/reports/{$report->id}/identity-request", [])
            ->assertUnauthorized();
    }

    public function test_identity_request_requires_a_reason_and_evidence_file(): void
    {
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->postJson("/api/reports/{$report->id}/identity-request", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['alasan', 'bukti']);

        $this->assertDatabaseCount('identity_requests', 0);
    }

    public function test_identity_request_rejects_unsupported_evidence_files(): void
    {
        Storage::fake('local');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->post(
            "/api/reports/{$report->id}/identity-request",
            [
                'alasan' => 'Diperlukan untuk pemeriksaan lanjutan.',
                'bukti' => UploadedFile::fake()->create('bukti.txt', 1, 'text/plain'),
            ],
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bukti');

        $this->assertDatabaseCount('identity_requests', 0);
        Storage::disk('local')->assertDirectoryEmpty('identity-requests');
    }

    public function test_identity_request_rejects_evidence_over_10_mb(): void
    {
        Storage::fake('local');
        $report = $this->createReport($this->createUser('santri'));
        Sanctum::actingAs($this->createUser('pimpinan'));

        $this->post(
            "/api/reports/{$report->id}/identity-request",
            [
                'alasan' => 'Diperlukan untuk pemeriksaan lanjutan.',
                'bukti' => UploadedFile::fake()->create('bukti.pdf', 10241, 'application/pdf'),
            ],
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('bukti');

        $this->assertDatabaseCount('identity_requests', 0);
        Storage::disk('local')->assertDirectoryEmpty('identity-requests');
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

    public function test_santri_can_set_identity_visibility_and_staff_sees_the_updated_name(): void
    {
        $owner = $this->createUser('santri');
        $report = $this->createReport($owner);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/reports/{$report->id}/identity", [
            'tampilkan_identitas' => true,
        ])
            ->assertOk()
            ->assertJsonPath('laporan.tampilkan_identitas', true)
            ->assertJsonPath('laporan.user.name', $owner->name);

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'tampilkan_identitas' => true,
        ]);

        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('data.0.user.name', $owner->name);

        Sanctum::actingAs($owner);

        $this->patchJson("/api/reports/{$report->id}/identity", [
            'tampilkan_identitas' => false,
        ])
            ->assertOk()
            ->assertJsonPath('laporan.tampilkan_identitas', false);

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'tampilkan_identitas' => false,
        ]);

        Sanctum::actingAs($this->createUser('pengurus'));

        $this->getJson('/api/reports')
            ->assertOk()
            ->assertJsonPath('data.0.user.name', 'Anonymous');
    }

    public function test_santri_can_toggle_identity_visibility_without_a_request_body(): void
    {
        $owner = $this->createUser('santri');
        $report = $this->createReport($owner);
        Sanctum::actingAs($owner);

        $this->patchJson("/api/reports/{$report->id}/identity")
            ->assertOk()
            ->assertJsonPath('laporan.tampilkan_identitas', true)
            ->assertJsonPath('laporan.user.name', $owner->name);

        $this->assertDatabaseHas('laporans', [
            'id' => $report->id,
            'tampilkan_identitas' => true,
        ]);
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

    private function submitIdentityRequest(Laporan $report, User $pimpinan): void
    {
        Sanctum::actingAs($pimpinan);

        $this->post(
            "/api/reports/{$report->id}/identity-request",
            [
                'alasan' => 'Diperlukan untuk pemeriksaan lanjutan.',
                'bukti' => UploadedFile::fake()->image('bukti.png'),
            ],
            ['Accept' => 'application/json'],
        )->assertCreated();
    }
}
