<?php

namespace App\Http\Controllers;

use App\Models\Santri;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
                ['Nama Santri', 'santri@example.test', '20260001', 'laki', '081234567890', 'PasswordSementara123'],
            ], null, 'A2');
            $sheet->fromArray([
                ['Isi kolom password dengan password sementara; santri wajib menggantinya setelah login.'],
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

        if ($headers !== self::HEADERS) {
            return response()->json([
                'message' => 'Header Excel tidak sesuai template.',
                'required_headers' => self::HEADERS,
            ], 422);
        }

        $previewRows = [];
        $errors = [];
        $seenEmails = [];
        $seenNis = [];

        foreach ($rows as $index => $values) {
            $rowNumber = $index + 2;
            $row = array_combine(self::HEADERS, array_pad(array_slice($values, 0, count(self::HEADERS)), count(self::HEADERS), null));

            if (collect($row)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }

            $row = array_map(fn ($value) => trim((string) $value), $row);
            $validator = Validator::make($row, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'nis' => ['required', 'string', 'max:255', 'unique:santris,nis'],
                'jenis_kelamin' => ['required', 'in:laki,perempuan'],
                'telepon_ortu' => ['required', 'string', 'min:10', 'max:20'],
                'password' => ['required', 'string', 'min:8', 'max:255'],
            ]);
            $rowErrors = $validator->errors()->toArray();
            $emailKey = strtolower($row['email']);

            if (isset($seenEmails[$emailKey])) {
                $rowErrors['email'][] = 'Email duplikat di dalam file.';
            }

            if (isset($seenNis[$row['nis']])) {
                $rowErrors['nis'][] = 'NIS duplikat di dalam file.';
            }

            $seenEmails[$emailKey] = true;
            $seenNis[$row['nis']] = true;

            if ($rowErrors !== []) {
                $errors[] = ['row' => $rowNumber, 'errors' => $rowErrors];
            }

            $previewRows[] = [
                'row' => $rowNumber,
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

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $values) {
                $row = array_combine(self::HEADERS, array_pad(array_slice($values, 0, count(self::HEADERS)), count(self::HEADERS), null));

                if (collect($row)->every(fn ($value) => trim((string) $value) === '')) {
                    continue;
                }

                $row = array_map(fn ($value) => trim((string) $value), $row);
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
            }
        });

        return response()->json([
            'imported' => count($previewRows),
            'message' => 'Data santri berhasil diimport. Setiap santri wajib mengganti password sementara saat login pertama.',
        ], 201);
    }
}
