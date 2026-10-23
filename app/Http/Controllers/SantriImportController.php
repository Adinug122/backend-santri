<?php

namespace App\Http\Controllers;

use App\Models\Santri;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SantriImportController extends Controller
{
    private const HEADERS = [
        'name',
        'email',
        'nis',
        'jenis_kelamin',
        'telepon_ortu',
    ];

    /**
     * Header lama dengan kolom password, masih diterima agar file sebelumnya tetap bisa diimport.
     */
    private const PASSWORD_HEADERS = [
        ...self::HEADERS,
        'password',
    ];

    /**
     * Header lama dengan id_santri dan password, masih diterima untuk kompatibilitas.
     */
    private const LEGACY_EXPORT_HEADERS = [
        'id_santri',
        ...self::PASSWORD_HEADERS,
    ];

    private const DEFAULT_PASSWORD = '12345678';

    /**
     * Lebar kolom yang sama untuk file export dan template.
     *
     * @var array<string, float>
     */
    private const COLUMN_WIDTHS = [
        'A' => 28,
        'B' => 32,
        'C' => 18,
        'D' => 16,
        'E' => 22,
    ];

    public function export(): StreamedResponse|JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data Santri');
            $sheet->fromArray(self::HEADERS, null, 'A1');

            $rowNumber = 2;
            Santri::query()
                ->with('user')
                ->orderBy('id')
                ->chunk(500, function ($santris) use ($sheet, &$rowNumber): void {
                    foreach ($santris as $santri) {
                        $sheet->setCellValueExplicit('A'.$rowNumber, $santri->user?->name ?? '', DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('B'.$rowNumber, $santri->user?->email ?? '', DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('C'.$rowNumber, $santri->nis ?? '', DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('D'.$rowNumber, $santri->jenis_kelamin, DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('E'.$rowNumber, $santri->telepon_ortu, DataType::TYPE_STRING);
                        $rowNumber++;
                    }
                });

            $this->applySheetLayout($sheet, 'E');
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'data-santri.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function template(): StreamedResponse|JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Data Santri');
            $sheet->fromArray(self::HEADERS, null, 'A1');
            $sheet->fromArray([
                ['Nama Santri', 'santri@example.test', '20260001', 'laki', '081234567890'],
            ], null, 'A2');
            $sheet->fromArray([
                ['Password santri baru otomatis dibuat 12345678 dan wajib diganti setelah login pertama.'],
                ['Password santri yang sudah ada tidak berubah saat import.'],
            ], null, 'A4');
            $this->applySheetLayout($sheet, 'E');
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'template-import-santri.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Cari santri yang sudah ada berdasarkan NIS atau email saat file tidak menyertakan id_santri.
     */
    private function findExistingSantri(string $nis, string $email): ?Santri
    {
        $byNis = Santri::with('user')->where('nis', $nis)->first();
        $byEmail = Santri::with('user')
            ->whereHas('user', fn ($query) => $query->where('email', $email))
            ->first();

        if ($byNis !== null && $byEmail !== null && $byNis->id !== $byEmail->id) {
            return null;
        }

        return $byNis ?? $byEmail;
    }

    private function applySheetLayout(Worksheet $sheet, string $lastColumn): void
    {
        foreach (self::COLUMN_WIDTHS as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
    }

    public function import(Request $request): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'in:preview,import'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:5120'],
        ]);

        $spreadsheet = IOFactory::load($validated['file']->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();

        if ($sheet->getHighestDataRow() > 501) {
            return response()->json([
                'message' => 'Maksimal 500 baris data per import.',
            ], 422);
        }

        $rows = $sheet->toArray(null, true, false, false);
        $headers = array_map(
            fn ($header) => strtolower(trim((string) $header)),
            array_shift($rows) ?? [],
        );

        $hasSantriIds = $headers === self::LEGACY_EXPORT_HEADERS;
        $hasPasswordColumn = $hasSantriIds || $headers === self::PASSWORD_HEADERS;

        if (! $hasPasswordColumn && $headers !== self::HEADERS) {
            return response()->json([
                'message' => 'Header Excel tidak sesuai template.',
                'required_headers' => self::HEADERS,
            ], 422);
        }

        $columnHeaders = match (true) {
            $hasSantriIds => self::LEGACY_EXPORT_HEADERS,
            $hasPasswordColumn => self::PASSWORD_HEADERS,
            default => self::HEADERS,
        };

        $previewRows = [];
        $importRows = [];
        $errors = [];
        $seenEmails = [];
        $seenNis = [];
        $seenSantriIds = [];

        foreach ($rows as $index => $values) {
            $rowNumber = $index + 2;
            $row = array_combine($columnHeaders, array_pad(array_slice($values, 0, count($columnHeaders)), count($columnHeaders), null));

            if (collect($row)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $row = array_map(fn ($value) => trim((string) $value), $row);
            $row['password'] = $hasPasswordColumn && $row['password'] !== '' ? $row['password'] : null;
            $santriId = $hasSantriIds && $row['id_santri'] !== '' ? $row['id_santri'] : null;
            $existingSantri = $hasSantriIds
                ? ($santriId !== null && ctype_digit($santriId)
                    ? Santri::with('user')->find((int) $santriId)
                    : null)
                : $this->findExistingSantri($row['nis'], $row['email']);
            $recordId = $existingSantri?->id ?? $santriId;

            $rules = [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users', 'email')->ignore($existingSantri?->users_id),
                ],
                'nis' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('santris', 'nis')->ignore($existingSantri?->id),
                ],
                'jenis_kelamin' => ['required', 'in:laki,perempuan'],
                'telepon_ortu' => ['required', 'string', 'min:10', 'max:20'],
            ];

            if ($hasSantriIds) {
                $rules['id_santri'] = ['nullable', 'integer', Rule::exists('santris', 'id')];
            }

            if ($hasPasswordColumn) {
                $rules['password'] = ['nullable', 'string', 'min:4', 'max:255'];
            }

            $validator = Validator::make($row, $rules);
            $rowErrors = $validator->errors()->toArray();
            $emailKey = strtolower($row['email']);

            if (isset($seenEmails[$emailKey])) {
                $rowErrors['email'][] = 'Email duplikat di dalam file.';
            }

            if (isset($seenNis[$row['nis']])) {
                $rowErrors['nis'][] = 'NIS duplikat di dalam file.';
            }

            if ($recordId !== null && isset($seenSantriIds[$recordId])) {
                $rowErrors['id_santri'][] = 'ID santri duplikat di dalam file.';
            }

            $seenEmails[$emailKey] = true;
            $seenNis[$row['nis']] = true;
            if ($recordId !== null) {
                $seenSantriIds[$recordId] = true;
            }

            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
            }

            $importRows[] = [
                'id_santri' => $existingSantri?->id,
                'name' => $row['name'],
                'email' => $row['email'],
                'nis' => $row['nis'],
                'jenis_kelamin' => $row['jenis_kelamin'],
                'telepon_ortu' => $row['telepon_ortu'],
                'password' => $row['password'] ?? '',
            ];

            $previewRows[] = [
                'row' => $rowNumber,
                'id_santri' => $existingSantri?->id,
                'name' => $row['name'],
                'email' => $row['email'],
                'nis' => $row['nis'],
                'jenis_kelamin' => $row['jenis_kelamin'],
                'telepon_ortu' => $row['telepon_ortu'],
                'valid' => $rowErrors === [],
            ];
        }

        if ($validated['action'] === 'preview') {
            return response()->json([
                'data' => $previewRows,
                'errors' => $errors,
                'can_import' => $previewRows !== [] && $errors === [],
                'message' => 'Preview import selesai; belum ada data yang disimpan.',
            ]);
        }

        if ($previewRows === [] || $errors !== []) {
            return response()->json([
                'data' => $previewRows,
                'errors' => $errors,
                'message' => 'Import dibatalkan karena file kosong atau memiliki baris tidak valid.',
            ], 422);
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($importRows, &$created, &$updated): void {
            foreach ($importRows as $row) {
                if ($row['id_santri'] !== null) {
                    $santri = Santri::with('user')->findOrFail($row['id_santri']);
                    $user = $santri->user;
                    $user->name = $row['name'];
                    $user->email = $row['email'];

                    if (($row['password'] ?? '') !== '') {
                        $user->password = $row['password'];
                    }

                    $user->save();
                    $santri->update([
                        'nis' => $row['nis'],
                        'jenis_kelamin' => $row['jenis_kelamin'],
                        'telepon_ortu' => $row['telepon_ortu'],
                    ]);
                    $updated++;

                    continue;
                }

                $user = User::create([
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'role' => 'santri',
                    'password' => ($row['password'] ?? '') !== '' ? $row['password'] : self::DEFAULT_PASSWORD,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);

                Santri::create([
                    'users_id' => $user->id,
                    'nis' => $row['nis'],
                    'jenis_kelamin' => $row['jenis_kelamin'],
                    'telepon_ortu' => $row['telepon_ortu'],
                ]);
                $created++;
            }
        });

        return response()->json([
            'imported' => count($previewRows),
            'created' => $created,
            'updated' => $updated,
            'message' => 'Data santri berhasil diimport.',
        ], 201);
    }
}
