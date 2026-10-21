<?php

namespace App\Http\Controllers;

use App\Models\Laporan;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * @return array<string, Builder>|null
     */
    private function queriesFor(User $user): ?array
    {
        if ($user->role === 'santri') {
            $mine = fn () => Laporan::query()->where('users_id', $user->id);

            return [
                'permintaan_identitas' => $mine()->whereHas('identityRequests', function (Builder $q): void {
                    $q->where('status', 'menunggu')->where(function (Builder $q): void {
                        $q->whereNull('laporans.santri_dibaca')
                            ->orWhereColumn('identity_requests.created_at', '>', 'laporans.santri_dibaca');
                    });
                }),

                'pesan_baru' => $mine()->whereHas('percakapans', function (Builder $q) use ($user): void {
                    $q->where('pengirim_id', '!=', $user->id)->where(function (Builder $q): void {
                        $q->whereNull('laporans.santri_dibaca')
                            ->orWhereColumn('percakapans.created_at', '>', 'laporans.santri_dibaca');
                    });
                }),

                'status_diubah' => $mine()
                    ->whereNotNull('status_diubah')
                    ->whereRaw('status_diubah > COALESCE(santri_dibaca, created_at)'),
            ];
        }

        $kolom = Laporan::readColumnFor($user->role);

        if ($kolom === null) {
            return null;
        }

        return [
            'laporan_baru' => Laporan::query()
                ->where('status', 'baru')
                ->whereNull("laporans.$kolom"),

            'pesan_baru' => Laporan::query()
                ->where('status', '!=', 'selesai')
                ->whereHas('percakapans', function (Builder $q) use ($user, $kolom): void {
                    $q->where('percakapans.pengirim_id', '!=', $user->id)
                        ->where(function (Builder $q) use ($kolom): void {
                            $q->whereNull("laporans.$kolom")
                                ->orWhereColumn('percakapans.created_at', '>', "laporans.$kolom");
                        });
                }),
        ];
    }

    public function summary(Request $request): JsonResponse
    {
        $queries = $this->queriesFor($request->user());

        if ($queries === null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = array_map(fn (Builder $q) => $q->count(), $queries);
        $total = array_sum($data);

        return response()->json([
            'message' => 'Berhasil mengambil notifikasi.',
            'code' => 'NOTIFICATION_FETCH_SUCCESS',
            'data' => $data + ['pembaruan' => $total],
        ]);
    }

    // Untuk halaman notifikasi: daftar laporan + alasannya, tanpa isi dan tanpa identitas.
    public function index(Request $request): JsonResponse
    {
        $queries = $this->queriesFor($request->user());

        if ($queries === null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $items = [];

        foreach ($queries as $jenis => $query) {
            foreach ($query->get(['laporans.id', 'laporans.no_laporan', 'laporans.kategori']) as $laporan) {
                $items[$laporan->id] ??= [
                    'laporan_id' => $laporan->id,
                    'no_laporan' => $laporan->no_laporan,
                    'kategori' => $laporan->kategori,
                    'alasan' => [],
                ];
                $items[$laporan->id]['alasan'][] = $jenis;
            }
        }

        return response()->json([
            'message' => 'Berhasil mengambil daftar notifikasi.',
            'code' => 'NOTIFICATION_LIST_SUCCESS',
            'data' => array_values($items),
        ]);
    }
}
