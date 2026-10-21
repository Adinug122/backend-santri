<?php

namespace App\Http\Controllers;

use App\Models\Laporan;
use App\Models\Santri;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

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

    public function account(): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $accounts = User::query()
            ->select([
                'id',
                'name',
                'email',
                'role',
                'is_active',
                'must_change_password',
                'created_at',
            ])
            ->with('santri:id,users_id,nis')
            ->orderBy('role')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $accounts,
            'message' => 'Data akun berhasil diambil',
        ]);
    }

    public function storeAccount(Request $request): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:4', 'max:255'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'role' => 'pengurus',
            'password' => $validated['password'],
            'is_active' => true,
            'must_change_password' => true,
        ]);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
            ],
            'message' => 'Akun pengurus berhasil dibuat.',
        ], Response::HTTP_CREATED);
    }

    public function isActiveAccount(User $user): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], Response::HTTP_FORBIDDEN);
        }

        if ((int) $user->id === (int) Auth::id()) {
            return response()->json([
                'message' => 'Akun yang sedang digunakan tidak dapat dinonaktifkan.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($user->role === 'pimpinan' && $user->is_active) {
            $activeLeaders = User::query()
                ->where('role', 'pimpinan')
                ->where('is_active', true)
                ->count();

            if ($activeLeaders <= 1) {
                return response()->json([
                    'message' => 'Pimpinan aktif terakhir tidak dapat dinonaktifkan.',
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
            ],
            'message' => $user->is_active
                ? 'Akun berhasil diaktifkan.'
                : 'Akun berhasil dinonaktifkan.',
        ]);
    }
}
