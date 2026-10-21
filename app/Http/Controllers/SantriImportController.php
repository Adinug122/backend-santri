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
        'password',
    ];

    private const EXPORT_HEADERS = [
        'id_santri',
        ...self::HEADERS,
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
            $sheet->fromArray(self::EXPORT_HEADERS, null, 'A1');

            $rowNumber = 2;
            Santri::query()
                ->with('user')
                ->orderBy('id')
                ->chunk(500, function ($santris) use ($sheet, &$rowNumber): void {
                    foreach ($santris as $santri) {
                        $sheet->setCellValueExplicit('A'.$rowNumber, (string) $santri->id, DataType::TYPE_NUMERIC);
                        $sheet->setCellValueExplicit('B'.$rowNumber, $santri->user?->name ?? '', DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('C'.$rowNumber, $santri->user?->email ?? '', DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('D'.$rowNumber, $santri->nis ?? '', DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('E'.$rowNumber, $santri->jenis_kelamin, DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('F'.$rowNumber, $santri->telepon_ortu, DataType::TYPE_STRING);
                        $sheet->setCellValueExplicit('G'.$rowNumber, '', DataType::TYPE_STRING);
                        $rowNumber++;
                    }
                });

            $sheet->getStyle('A1:G1')->getFont()->setBold(true);
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
                ['Nama Santri', 'santri@example.test', '20260001', 'laki', '081234567890', 'Pass1234'],
            ], null, 'A2');
            $sheet->fromArray([
                ['Password wajib diisi untuk santri baru; kosongkan untuk santri lama jika tidak ingin mengubah password.'],
                ['Santri yang baru dibuat wajib mengganti passwordnya setelah login pertama.'],
            ], null, 'A4');
            $sheet->getStyle('A1:F1')->getFont()->setBold(true);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'template-import-santri.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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

        $hasSantriIds = $headers === self::EXPORT_HEADERS;

        if (! $hasSantriIds && $headers !== self::HEADERS) {
            return response()->json([
                'message' => 'Header Excel tidak sesuai template.',
                'required_headers' => self::HEADERS,
            ], 422);
        }

        $previewRows = [];
        $importRows = [];
        $errors = [];
        $seenEmails = [];
        $seenNis = [];
        $seenSantriIds = [];

        foreach ($rows as $index => $values) {
            $rowNumber = $index + 2;
            $columnHeaders = $hasSantriIds ? self::EXPORT_HEADERS : self::HEADERS;
            $row = array_combine($columnHeaders, array_pad(array_slice($values, 0, count($columnHeaders)), count($columnHeaders), null));

            if (collect($row)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $row = array_map(fn ($value) => trim((string) $value), $row);
            $santriId = $hasSantriIds && $row['id_santri'] !== '' ? $row['id_santri'] : null;
            $existingSantri = $santriId !== null && ctype_digit($santriId)
                ? Santri::with('user')->find((int) $santriId)
                : null;

            $validator = Validator::make($row, [
                'id_santri' => ['nullable', 'integer', Rule::exists('santris', 'id')],
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
                'password' => [
                    $existingSantri === null ? 'required' : 'nullable',
                    'string',
                    'min:4',
                    'max:255',
                ],
            ]);
            $rowErrors = $validator->errors()->toArray();
            $emailKey = strtolower($row['email']);

            if (isset($seenEmails[$emailKey])) {
                $rowErrors['email'][] = 'Email duplikat di dalam file.';
            }

            if (isset($seenNis[$row['nis']])) {
                $rowErrors['nis'][] = 'NIS duplikat di dalam file.';
            }

            if ($santriId !== null && isset($seenSantriIds[$santriId])) {
                $rowErrors['id_santri'][] = 'ID santri duplikat di dalam file.';
            }

            $seenEmails[$emailKey] = true;
            $seenNis[$row['nis']] = true;
            if ($santriId !== null) {
                $seenSantriIds[$santriId] = true;
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
                'password' => $row['password'],
            ];

            $previewRows[] = [
                'row' => $rowNumber,
                'id_santri' => $santriId,
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

                    if ($row['password'] !== '') {
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
                    'password' => $row['password'],
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
