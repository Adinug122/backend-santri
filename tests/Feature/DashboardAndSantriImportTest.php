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
            ->assertJsonPath('data.jumlah_terlambat', 0)
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

        $this->postJson('/api/auth/login', [
            'identifier' => 'pengurus.baru@example.test',
            'password' => 'TempPassword123',
        ])
            ->assertOk()
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.santri', null);
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
        $this->assertNull($sheet->getCell('F1')->getValue());

        $spreadsheet->disconnectWorksheets();
        unlink($path);
    }

    public function test_pimpinan_can_export_santri_without_ids_or_passwords(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $santriUser = $this->createUser('santri', 'santri.export@example.test');
        Santri::create([
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

        $this->assertSame('name', $sheet->getCell('A1')->getValue());
        $this->assertSame('email', $sheet->getCell('B1')->getValue());
        $this->assertSame('nis', $sheet->getCell('C1')->getValue());
        $this->assertSame('jenis_kelamin', $sheet->getCell('D1')->getValue());
        $this->assertSame('telepon_ortu', $sheet->getCell('E1')->getValue());
        $this->assertNull($sheet->getCell('F1')->getValue());
        $this->assertSame($santriUser->name, $sheet->getCell('A2')->getValue());
        $this->assertSame($santriUser->email, $sheet->getCell('B2')->getValue());
        $this->assertSame('20260010', $sheet->getCell('C2')->getValue());
        $this->assertSame('laki', $sheet->getCell('D2')->getValue());
        $this->assertSame('081234567890', $sheet->getCell('E2')->getValue());
        $this->assertNull($sheet->getCell('F2')->getValue());

        $spreadsheet->disconnectWorksheets();
        unlink($path);
    }

    public function test_export_and_template_use_the_same_column_widths(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));

        $template = $this->downloadWorkbook('/api/admin/santri/template');
        $export = $this->downloadWorkbook('/api/admin/santri/export');

        foreach (['A', 'B', 'C', 'D', 'E'] as $column) {
            $this->assertSame(
                $template->getActiveSheet()->getColumnDimension($column)->getWidth(),
                $export->getActiveSheet()->getColumnDimension($column)->getWidth(),
                "Lebar kolom {$column} berbeda antara template dan export.",
            );
        }

        $template->disconnectWorksheets();
        $export->disconnectWorksheets();
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
            ['Siti Test', 'siti.test@example.test', '20260002', 'perempuan', '081234567891'],
        ], false, false);

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
        $importedUser = User::where('email', 'siti.test@example.test')->firstOrFail();
        $this->assertSame('santri', $importedUser->role);
        $this->assertTrue($importedUser->must_change_password);
        $this->assertTrue(Hash::check('12345678', $importedUser->password));
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
                ['Invalid Email', 'Bukan Email', '20260003', 'perempuan', '081234567892'],
            ], false, false),
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
                ['Password Test', 'password.test@example.test', '20260004', 'laki', '081234567893'],
            ], false, false),
        ], ['Accept' => 'application/json'])->assertCreated();

        $santri = User::where('email', 'password.test@example.test')->firstOrFail();

        $this->assertDatabaseHas('users', [
            'id' => $santri->id,
            'must_change_password' => true,
        ]);
        $this->assertTrue(Hash::check('12345678', $santri->password));

        $this->postJson('/api/auth/login', [
            'identifier' => 'password.test@example.test',
            'password' => '12345678',
        ])
            ->assertOk()
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.santri.nis', '20260004')
            ->assertJsonPath('user.santri.telepon_ortu', '081234567893');

        Sanctum::actingAs($santri);

        $this->getJson('/api/reports/mine')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->postJson('/api/auth/password', [
            'current_password' => '12345678',
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
        $sheet->setCellValue('A2', 'Nama Hasil Edit');
        $sheet->setCellValue('C2', '20260032');
        $sheet->setCellValue('A3', 'Santri Baru Dari File');
        $sheet->setCellValue('B3', 'baru.dari.file@example.test');
        $sheet->setCellValue('C3', '20260033');
        $sheet->setCellValue('D3', 'perempuan');
        $sheet->setCellValue('E3', '081234567899');

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

    public function test_idless_import_updates_santri_matched_by_nis_or_email(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $byNisUser = $this->createUser('santri', 'by.nis@example.test');
        $byNis = Santri::create([
            'users_id' => $byNisUser->id,
            'nis' => '20260060',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);
        $byEmailUser = $this->createUser('santri', 'by.email@example.test');
        $byEmail = Santri::create([
            'users_id' => $byEmailUser->id,
            'nis' => '20260050',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu' => '081234567891',
        ]);
        $byNisPassword = $byNisUser->password;
        $byEmailPassword = $byEmailUser->password;

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['Nama Baru Via NIS', 'by.nis.baru@example.test', '20260060', 'laki', '081234567892'],
                ['Nama Baru Via Email', 'by.email@example.test', '20260070', 'perempuan', '081234567893'],
            ], false, false),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('imported', 2)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('updated', 2);

        $this->assertDatabaseHas('users', [
            'id' => $byNisUser->id,
            'name' => 'Nama Baru Via NIS',
            'email' => 'by.nis.baru@example.test',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $byNis->id,
            'nis' => '20260060',
            'telepon_ortu' => '081234567892',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $byEmailUser->id,
            'name' => 'Nama Baru Via Email',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $byEmail->id,
            'nis' => '20260070',
            'telepon_ortu' => '081234567893',
        ]);
        $this->assertSame($byNisPassword, $byNisUser->fresh()->password);
        $this->assertSame($byEmailPassword, $byEmailUser->fresh()->password);
    }

    public function test_idless_import_rejects_conflicting_nis_and_email_owners(): void
    {
        Sanctum::actingAs($this->createUser('pimpinan', 'pimpinan@example.test'));
        $nisOwnerUser = $this->createUser('santri', 'pemilik.nis@example.test');
        $nisOwner = Santri::create([
            'users_id' => $nisOwnerUser->id,
            'nis' => '20260040',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);
        $emailOwnerUser = $this->createUser('santri', 'pemilik.email@example.test');
        $emailOwner = Santri::create([
            'users_id' => $emailOwnerUser->id,
            'nis' => '20260041',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu' => '081234567891',
        ]);

        $this->post('/api/admin/santri/import', [
            'action' => 'import',
            'file' => $this->createWorkbook([
                ['Konflik Pemilik', 'pemilik.email@example.test', '20260040', 'laki', '081234567892'],
            ], false, false),
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.row', 2)
            ->assertJsonPath('errors.0.errors.email.0', 'The email has already been taken.')
            ->assertJsonPath('errors.0.errors.nis.0', 'The nis has already been taken.');

        $this->assertDatabaseHas('santris', [
            'id' => $nisOwner->id,
            'nis' => '20260040',
        ]);
        $this->assertDatabaseHas('santris', [
            'id' => $emailOwner->id,
            'nis' => '20260041',
        ]);
        $this->assertDatabaseMissing('users', ['name' => 'Konflik Pemilik']);
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

    private function createWorkbook(array $dataRows, bool $includeSantriIds = false, bool $includePassword = true): UploadedFile
    {
        $headers = ['name', 'email', 'nis', 'jenis_kelamin', 'telepon_ortu'];

        if ($includePassword) {
            $headers[] = 'password';
        }

        if ($includeSantriIds) {
            array_unshift($headers, 'id_santri');
        }

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([$headers, ...$dataRows]);

        return $this->uploadWorkbook($spreadsheet);
    }

    private function exportWorkbook(): Spreadsheet
    {
        return $this->downloadWorkbook('/api/admin/santri/export');
    }

    private function downloadWorkbook(string $url): Spreadsheet
    {
        $response = $this->get($url)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'santri-download-');
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
