<?php

namespace App\Http\Controllers;

use App\Models\Laporan;
use App\Models\Santri;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function summary(): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $statusCounts = Laporan::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $categoryCounts = Laporan::query()
            ->select('kategori', DB::raw('count(*) as total'))
            ->groupBy('kategori')
            ->orderBy('kategori')
            ->get()
            ->map(fn ($row) => [
                'kategori' => $row->kategori,
                'total' => (int) $row->total,
            ]);

        return response()->json([
            'data' => [
                'total_santri' => Santri::count(),
                'total_laporan' => Laporan::count(),
                'laporan_per_status' => [
                    'baru' => (int) ($statusCounts['baru'] ?? 0),
                    'diproses' => (int) ($statusCounts['diproses'] ?? 0),
                    'selesai' => (int) ($statusCounts['selesai'] ?? 0),
                ],
                'laporan_per_kategori' => $categoryCounts,
            ],
            'message' => 'Ringkasan dashboard berhasil diambil',
        ]);
    }
}
