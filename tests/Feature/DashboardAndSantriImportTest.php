<?php

namespace Tests\Feature;

use App\Models\Laporan;
use App\Models\Santri;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DashboardAndSantriImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pimpinan_can_get_dashboard_summary_aggregates(): void
    {
        $pimpinan = $this->createUser('pimpinan', 'pimpinan@example.test');
        $santri = $this->createUser('santri', 'santri@example.test');
        Santri::create([
            'users_id' => $santri->id,
            'nis' => '20260001',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);
        $this->createReport($santri, 'baru', 'Fasilitas');
        $this->createReport($santri, 'diproses', 'Keamanan');
        Sanctum::actingAs($pimpinan);

        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('data.total_santri', 1)
            ->assertJsonPath('data.total_laporan', 2)
            ->assertJsonPath('data.laporan_per_status.baru', 1)
            ->assertJsonPath('data.laporan_per_status.diproses', 1)
            ->assertJsonPath('data.laporan_per_status.selesai', 0)
            ->assertJsonPath('data.laporan_per_kategori.0.kategori', 'Fasilitas')
            ->assertJsonPath('data.laporan_per_kategori.0.total', 1);
    }

    public function test_non_pimpinan_cannot_get_dashboard_summary(): void
    {
        Sanctum::actingAs($this->createUser('pengurus', 'pengurus@example.test'));

        $this->getJson('/api/dashboard/summary')->assertForbidden();
    }

    public function test_pimpinan_can_list_accounts_without_exposing_passwords(): void
    {
        $pimpinan = $this->createUser('pimpinan', 'pimpinan@example.test');
        $this->createUser('santri', 'santri@example.test');
        Sanctum::actingAs($pimpinan);

        $this->getJson('/api/admin/accounts')
            ->assertOk()
            ->assertJsonPath('data.1.email', 'santri@example.test')
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonMissingPath('data.1.password');
    }

    public function test_pimpinan_can_create_active_pengurus_account_with_temporary_password(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $this->postJson('/api/admin/accounts', [
            'name' => 'Pengurus Baru',
            'email' => 'pengurus.baru@example.test',
            'password' => 'TempPassword123',
            'role' => 'pimpinan',
            'is_active' => false,
            'must_change_password' => false,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Pengurus Baru')
            ->assertJsonPath('data.email', 'pengurus.baru@example.test')
            ->assertJsonPath('data.role', 'pengurus')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.must_change_password', true)
            ->assertJsonMissingPath('data.password');

        $user = User::where('email', 'pengurus.baru@example.test')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'pengurus',
            'is_active' => true,
            'must_change_password' => true,
        ]);
        $this->assertTrue(Hash::check('TempPassword123', $user->password));
    }

    public function test_unauthenticated_request_returns_401_when_creating_pengurus_account(): void
    {
        $this->postJson('/api/admin/accounts', [
            'name' => 'Pengurus Baru',
            'email' => 'pengurus.baru@example.test',
            'password' => 'TempPassword123',
        ])->assertUnauthorized();

        $this->assertDatabaseMissing('users', [
            'email' => 'pengurus.baru@example.test',
        ]);
    }

    public function test_account_creation_returns_422_when_required_fields_are_missing(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $this->postJson('/api/admin/accounts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->assertDatabaseMissing('users', [
            'email' => 'pengurus.baru@example.test',
        ]);
    }

    public function test_account_creation_returns_422_when_email_is_invalid(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $this->postJson('/api/admin/accounts', [
            'name' => 'Pengurus Baru',
            'email' => 'bukan-email',
            'password' => 'TempPassword123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertDatabaseMissing('users', [
            'email' => 'bukan-email',
        ]);
    }

    public function test_account_creation_returns_422_when_email_is_already_used(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $this->createUser('pengurus', 'pengurus.lama@example.test');

        $this->postJson('/api/admin/accounts', [
            'name' => 'Pengurus Duplikat',
            'email' => 'pengurus.lama@example.test',
            'password' => 'TempPassword123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(1, User::where('email', 'pengurus.lama@example.test')->count());
    }

    public function test_account_creation_returns_422_when_password_is_too_short(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $this->postJson('/api/admin/accounts', [
            'name' => 'Pengurus Baru',
            'email' => 'pengurus.baru@example.test',
            'password' => 'abc',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertDatabaseMissing('users', [
            'email' => 'pengurus.baru@example.test',
        ]);
    }

    public function test_non_pimpinan_cannot_list_or_toggle_accounts(): void
    {
        $pengurus = $this->createUser('pengurus', 'pengurus@example.test');
        $santri = $this->createUser('santri', 'santri@example.test');
        Sanctum::actingAs($pengurus);

        $this->getJson('/api/admin/accounts')->assertForbidden();
        $this->postJson('/api/admin/accounts', [
            'name' => 'Pengurus Baru',
            'email' => 'pengurus.baru@example.test',
            'password' => 'TempPassword123',
        ])->assertForbidden();
        $this->patchJson("/api/admin/accounts/{$santri->id}/status")->assertForbidden();

        $this->assertDatabaseMissing('users', [
            'email' => 'pengurus.baru@example.test',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'is_active' => true,
        ]);
    }

    public function test_pimpinan_can_toggle_account_and_deactivation_revokes_its_tokens(): void
    {
        $pimpinan = $this->createUser('pimpinan', 'pimpinan@example.test');
        $santri = $this->createUser('santri', 'santri@example.test');
        $token = $santri->createToken('existing-session');
        Sanctum::actingAs($pimpinan);

        $this->patchJson("/api/admin/accounts/{$santri->id}/status")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_pimpinan_cannot_deactivate_themselves_or_the_last_active_pimpinan(): void
    {
        $pimpinan = $this->createUser('pimpinan', 'pimpinan@example.test');
        $secondPimpinan = $this->createUser('pimpinan', 'pimpinan2@example.test');
        Sanctum::actingAs($pimpinan);

        $this->patchJson("/api/admin/accounts/{$pimpinan->id}/status")->assertUnprocessable();

        $secondPimpinan->update(['is_active' => false]);
        $this->patchJson("/api/admin/accounts/{$pimpinan->id}/status")->assertUnprocessable();

        $this->assertDatabaseHas('users', [
            'id' => $pimpinan->id,
            'is_active' => true,
        ]);
    }

    public function test_pimpinan_can_download_excel_template(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $response = $this->get('/api/admin/santri/template')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition', 'attachment; filename=template-import-santri.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'santri-template-');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('name', $sheet->getCell('A1')->getValue());
        $this->assertSame('email', $sheet->getCell('B1')->getValue());
        $this->assertSame('nis', $sheet->getCell('C1')->getValue());
        $this->assertSame('jenis_kelamin', $sheet->getCell('D1')->getValue());
        $this->assertSame('telepon_ortu', $sheet->getCell('E1')->getValue());
        $this->assertSame('password', $sheet->getCell('F1')->getValue());
        $this->assertNull($sheet->getCell('G1')->getValue());

        $spreadsheet->disconnectWorksheets();
        unlink($path);
    }

    public function test_pimpinan_can_export_santri_with_update_ids_and_blank_passwords(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $santriUser = $this->createUser('santri', 'santri.export@example.test');
        $santri = Santri::create([
            'users_id' => $santriUser->id,
            'nis' => '20260010',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);

        $response = $this->get('/api/admin/santri/export')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->assertHeader('content-disposition', 'attachment; filename=data-santri.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'santri-export-');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('id_santri', $sheet->getCell('A1')->getValue());
        $this->assertSame('name', $sheet->getCell('B1')->getValue());
        $this->assertSame('email', $sheet->getCell('C1')->getValue());
        $this->assertSame('nis', $sheet->getCell('D1')->getValue());
        $this->assertSame('jenis_kelamin', $sheet->getCell('E1')->getValue());
        $this->assertSame('telepon_ortu', $sheet->getCell('F1')->getValue());
        $this->assertSame('password', $sheet->getCell('G1')->getValue());
        $this->assertSame((string) $santri->id, (string) $sheet->getCell('A2')->getValue());
        $this->assertSame($santriUser->name, $sheet->getCell('B2')->getValue());
        $this->assertNull($sheet->getCell('G2')->getValue());

        $spreadsheet->disconnectWorksheets();
        unlink($path);
    }

    public function test_non_pimpinan_cannot_access_santri_import_endpoints(): void
    {
        Sanctum::actingAs($this->createUser('pengurus', 'pengurus@example.test'));

        $this->getJson('/api/admin/santri/export')->assertForbidden();
        $this->getJson('/api/admin/santri/template')->assertForbidden();
        $this->postJson('/api/admin/santri/import')->assertForbidden();
    }

    public function test_import_preview_does_not_save_and_import_saves_valid_rows(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $makeFile = fn () => $this->createWorkbook([
            ['Siti Test', 'siti.test@example.test', '20260002', 'perempuan', '081234567891', 'TempPassword123'],
        ]);

        $this->post('/api/admin/santri/import', [
            'action' => 'preview',
            'file' => $makeFile(),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('can_import', true)
            ->assertJsonPath('data.0.nis', '20260002')
            ->assertJsonMissingPath('data.0.password');

        $this->assertDatabaseMissing('santris', ['nis' => '20260002']);

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $makeFile(),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 1);

        $this->assertDatabaseHas('santris', [
            'nis' => '20260002',
            'jenis_kelamin' => 'perempuan',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'siti.test@example.test',
            'role' => 'santri',
            'must_change_password' => true,
        ]);
    }

    public function test_import_updates_santri_by_id_without_changing_blank_password_or_deleting_missing_rows(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $existingUser = $this->createUser('santri', 'old.santri@example.test');
        $existingSantri = Santri::create([
            'users_id' => $existingUser->id,
            'nis' => '20260020',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);
        $untouchedUser = $this->createUser('santri', 'untouched.santri@example.test');
        $untouchedSantri = Santri::create([
            'users_id' => $untouchedUser->id,
            'nis' => '20260021',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu' => '081234567891',
        ]);
        $existingPassword = $existingUser->password;

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                [$existingSantri->id, 'Nama Diperbarui', $existingUser->email, '20260022', 'perempuan', '081234567892', ''],
            ], true),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'name' => 'Nama Diperbarui',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $existingSantri->id,
            'nis' => '20260022',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu' => '081234567892',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $untouchedSantri->id,
            'nis' => '20260021',
        ]);
        $this->assertSame($existingPassword, $existingUser->fresh()->password);
    }

    public function test_import_requires_new_santri_password_to_have_at_least_four_characters(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['Santi Test', 'santi.test@example.test', '20260023', 'perempuan', '081234567893', 'abc'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.errors.password.0', 'The password field must be at least 4 characters.');

        $this->assertDatabaseMissing('users', [
            'email' => 'santi.test@example.test',
        ]);
    }

    public function test_import_rejects_invalid_rows_without_creating_any_users(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['Invalid Email', 'Bukan Email', '20260003', 'perempuan', '081234567892', 'TempPassword123'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 2);

        $this->assertDatabaseMissing('santris', ['nis' => '20260003']);
        $this->assertDatabaseMissing('users', ['email' => 'Bukan Email']);
    }

    public function test_newly_imported_santri_must_change_password_before_using_protected_apis(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['Password Test', 'password.test@example.test', '20260004', 'laki', '081234567893', 'TempPassword123'],
            ]),
        ], ['Accept' => 'application/json'])->assertCreated();

        $santri = User::where('email', 'password.test@example.test')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'must_change_password' => true,
        ]);

        Sanctum::actingAs($santri);

        $this->getJson('/api/reports/mine')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->postJson('/api/auth/password', [
            'current_password' => 'TempPassword123',
            'password' => 'PasswordBaru123',
            'password_confirmation' => 'PasswordBaru123',
        ])->assertOk();

        $this->getJson('/api/reports/mine')->assertOk();
    }

    public function test_pengurus_can_use_protected_apis_with_temporary_password_flag(): void
    {
        $pengurus = $this->createUser('pengurus', 'pengurus.temporary@example.test');
        $pengurus->update(['must_change_password' => true]);
        Sanctum::actingAs($pengurus);

        $this->getJson('/api/reports')->assertOk();
    }

    public function test_exported_file_can_be_reimported_without_treating_the_same_account_as_duplicate(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $santriUser = $this->createUser('santri', 'roundtrip@example.test');
        $santri = Santri::create([
            'users_id' => $santriUser->id,
            'nis' => '20260030',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->uploadWorkbook($this->exportWorkbook()),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('users', [
            'id' => $santriUser->id,
            'email' => 'roundtrip@example.test',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $santri->id,
            'nis' => '20260030',
        ]);
    }

    public function test_exported_file_can_be_edited_and_reimported_to_update_and_create_santri(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $santriUser = $this->createUser('santri', 'edited@example.test');
        $santri = Santri::create([
            'users_id' => $santriUser->id,
            'nis' => '20260031',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);

        $workbook = $this->exportWorkbook();
        $sheet = $workbook->getActiveSheet();
        $sheet->setCellValue('B2', 'Nama Hasil Edit');
        $sheet->setCellValue('D2', '20260032');
        $sheet->setCellValue('A3', null);
        $sheet->setCellValue('B3', 'Santri Baru Dari File');
        $sheet->setCellValue('C3', 'baru.dari.file@example.test');
        $sheet->setCellValue('D3', '20260033');
        $sheet->setCellValue('E3', 'perempuan');
        $sheet->setCellValue('F3', '081234567899');
        $sheet->setCellValue('G3', 'PasswordBaru123');

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->uploadWorkbook($workbook),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('users', [
            'id' => $santriUser->id,
            'name' => 'Nama Hasil Edit',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $santri->id,
            'nis' => '20260032',
        ]);
        $this->assertDatabaseHas('santris', [
            'nis' => '20260033',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu' => '081234567899',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'baru.dari.file@example.test',
            'role' => 'santri',
        ]);
    }

    public function test_import_rejects_rows_whose_email_or_nis_belongs_to_another_santri(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $otherUser = $this->createUser('santri', 'pemilik@example.test');
        $otherSantri = Santri::create([
            'users_id' => $otherUser->id,
            'nis' => '20260040',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['Email Duplikat', 'pemilik@example.test', '20260041', 'laki', '081234567891', 'TempPassword123'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 2)
            ->assertJsonPath('errors.0.errors.email.0', 'The email has already been taken.');

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['NIS Duplikat', 'nis.duplikat@example.test', '20260040', 'laki', '081234567891', 'TempPassword123'],
            ]),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 2)
            ->assertJsonPath('errors.0.errors.nis.0', 'The nis has already been taken.');

        $this->assertDatabaseMissing('users', ['email' => 'nis.duplikat@example.test']);
        $this->assertDatabaseHas('santris', [
            'id' => $otherSantri->id,
            'nis' => '20260040',
        ]);
    }

    private function createUser(string $role, string $email): User
    {
        return User::create([
            'name' => ucfirst($role).' Test',
            'email' => $email,
            'role' => $role,
            'password' => 'password123',
            'is_active' => true,
        ]);
    }

    private function createReport(User $owner, string $status, string $category): Laporan
    {
        static $sequence = 0;
        $sequence++;

        return Laporan::create([
            'users_id' => $owner->id,
            'no_laporan' => 'LAP-DASH-'.$sequence,
            'kategori' => $category,
            'isi' => 'Contoh laporan.',
            'tampilkan_identitas' => false,
            'status' => $status,
            'waktu' => now(),
        ]);
    }

    private function createWorkbook(array $dataRows, bool $includeSantriIds = false): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            $includeSantriIds
                ? ['id_santri', 'name', 'email', 'nis', 'jenis_kelamin', 'telepon_ortu', 'password']
                : ['name', 'email', 'nis', 'jenis_kelamin', 'telepon_ortu', 'password'],
            ...$dataRows,
        ]);

        return $this->uploadWorkbook($spreadsheet);
    }

    private function exportWorkbook(): Spreadsheet
    {
        $response = $this->get('/api/admin/santri/export')->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'santri-export-');
        file_put_contents($path, $response->streamedContent());
        $spreadsheet = IOFactory::load($path);
        unlink($path);

        return $spreadsheet;
    }

    private function uploadWorkbook(Spreadsheet $spreadsheet): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'santri-import-');
        (new Xlsx($spreadsheet))->save($path);
        $uploadedFile = UploadedFile::fake()->createWithContent(
            'santri-import.xlsx',
            file_get_contents($path),
        );
        unlink($path);
        $spreadsheet->disconnectWorksheets();

        return $uploadedFile;
    }
}
