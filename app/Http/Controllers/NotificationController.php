<?php

namespace App\Http\Controllers;

use App\Models\IdentityRequest;
use App\Models\Laporan;
use App\Models\Percakapan;
use App\Models\User;
use Carbon\CarbonInterface;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

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

    #[Endpoint(
        title: 'Ringkasan notifikasi',
        description: 'Mengembalikan jumlah laporan yang memiliki pembaruan untuk pengguna yang sedang login. Kunci data bergantung pada role: santri menerima permintaan_identitas, pesan_baru, dan status_diubah; pengurus/pimpinan menerima laporan_baru dan pesan_baru. Nilai pembaruan adalah jumlah seluruh kategori, sehingga satu laporan dapat dihitung lebih dari sekali. Respons menyertakan ETag agar polling dapat memakai If-None-Match.',
    )]
    #[Response(
        description: 'Ringkasan notifikasi berhasil diambil.',
        type: "array{message: 'Berhasil mengambil notifikasi.', code: 'NOTIFICATION_FETCH_SUCCESS', data: array{permintaan_identitas?: int, pesan_baru?: int, status_diubah?: int, laporan_baru?: int, pembaruan: int}}",
    )]
    public function summary(Request $request): JsonResponse
    {
        $queries = $this->queriesFor($request->user());

        if ($queries === null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = array_map(fn (Builder $q) => $q->count(), $queries);
        $total = array_sum($data);

        $payload = [
            'message' => 'Berhasil mengambil notifikasi.',
            'code' => 'NOTIFICATION_FETCH_SUCCESS',
            'data' => (object) ($data + ['pembaruan' => $total]),
        ];

        $etag = md5((string) json_encode($payload));

        if (trim((string) $request->header('If-None-Match')) === '"'.$etag.'"') {
            return response()->json(null, 304)->setEtag($etag);
        }

        return response()->json($payload)->setEtag($etag);
    }

    #[Endpoint(
        title: 'Daftar notifikasi',
        description: 'Mengembalikan notifikasi belum dibaca untuk pengguna yang sedang login, satu item per kategori per laporan (sama dengan granularitas ringkasan), diurutkan dari yang terbaru. Daftar ini tidak menandai laporan sebagai sudah dibaca; penandaan terjadi saat detail laporan atau pesan dibuka.',
    )]
    #[Response(
        description: 'Daftar notifikasi berhasil diambil.',
        type: "array{message: 'Berhasil mengambil daftar notifikasi.', code: 'NOTIFICATION_LIST_SUCCESS', data: array<int, array{id: string, tipe: 'laporan_baru'|'pesan_baru'|'status_diubah'|'permintaan_identitas', laporan_id: int, no_laporan: string, kategori: string, waktu: string|null, status_baru: bool, pengirim: array{id: int, name: string, role: string}|null, preview: string, url: string}>, meta: array{current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null}}",
    )]
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $queries = $this->queriesFor($user);

        if ($queries === null) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        $notifications = collect();

        foreach ($queries as $tipe => $query) {
            $relations = match ($tipe) {
                'permintaan_identitas' => ['identityRequests.user'],
                'pesan_baru' => ['percakapans.pengirim'],
                'laporan_baru' => ['user'],
                default => [],
            };

            if ($relations !== []) {
                $query->with($relations);
            }

            foreach ($query->get() as $laporan) {
                $notifications->push($this->notificationPayload($laporan, $tipe, $user));
            }
        }

        $notifications = $notifications
            ->sort(function (array $a, array $b): int {
                $byTime = ($b['waktu']?->getTimestampMs() ?? 0) <=> ($a['waktu']?->getTimestampMs() ?? 0);

                return $byTime !== 0 ? $byTime : $b['laporan_id'] <=> $a['laporan_id'];
            })
            ->values();

        $paginator = new LengthAwarePaginator(
            $notifications->forPage($page, $perPage)->values(),
            $notifications->count(),
            $perPage,
            $page,
        );

        $items = collect($paginator->items())
            ->map(function (array $item): array {
                $item['waktu'] = $item['waktu']?->toIso8601String();

                return $item;
            })
            ->all();

        return response()->json([
            'message' => 'Berhasil mengambil daftar notifikasi.',
            'code' => 'NOTIFICATION_LIST_SUCCESS',
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * @return array{id: string, tipe: string, laporan_id: int, no_laporan: string, kategori: string, waktu: CarbonInterface|null, status_baru: bool, pengirim: array{id: int, name: string, role: string}|null, preview: string, url: string}
     */
    private function notificationPayload(Laporan $laporan, string $tipe, User $user): array
    {
        $readColumn = Laporan::readColumnFor($user->role);
        $readAt = $readColumn !== null ? $laporan->{$readColumn} : null;
        $identityVisible = (bool) $laporan->tampilkan_identitas
            || (int) $laporan->users_id === (int) $user->id;

        [$waktu, $pengirim, $preview] = match ($tipe) {
            'permintaan_identitas' => $this->identityNotification($laporan, $readAt, $identityVisible),
            'pesan_baru' => $this->messageNotification($laporan, $user, $readAt, $identityVisible),
            'status_diubah' => [
                $laporan->status_diubah,
                null,
                "Status laporan diubah menjadi {$laporan->status}.",
            ],
            default => [
                $laporan->created_at,
                $this->senderPayload($laporan->user, $laporan, $identityVisible),
                Str::limit(Str::squish((string) $laporan->isi), 120),
            ],
        };

        return [
            'id' => "{$tipe}:{$laporan->id}",
            'tipe' => $tipe,
            'laporan_id' => $laporan->id,
            'no_laporan' => $laporan->no_laporan,
            'kategori' => $laporan->kategori,
            'waktu' => $waktu,
            'status_baru' => $laporan->status === 'baru',
            'pengirim' => $pengirim,
            'preview' => $preview,
            'url' => "/api/reports/{$laporan->id}",
        ];
    }

    /**
     * @return array{0: CarbonInterface|null, 1: array{id: int, name: string, role: string}|null, 2: string}
     */
    private function identityNotification(Laporan $laporan, ?CarbonInterface $readAt, bool $identityVisible): array
    {
        $identityRequest = $laporan->identityRequests
            ->filter(fn (IdentityRequest $request): bool => $request->status === 'menunggu'
                && $this->isAfter($request->created_at, $readAt))
            ->sortByDesc('created_at')
            ->first();

        return [
            $identityRequest?->created_at ?? $laporan->created_at,
            $this->senderPayload($identityRequest?->user, $laporan, $identityVisible),
            $identityRequest !== null
                ? Str::limit(Str::squish($identityRequest->alasan), 120)
                : '',
        ];
    }

    /**
     * @return array{0: CarbonInterface|null, 1: array{id: int, name: string, role: string}|null, 2: string}
     */
    private function messageNotification(Laporan $laporan, User $user, ?CarbonInterface $readAt, bool $identityVisible): array
    {
        $message = $laporan->percakapans
            ->filter(fn (Percakapan $message): bool => (int) $message->pengirim_id !== (int) $user->id
                && $this->isAfter($message->created_at, $readAt))
            ->sortByDesc('created_at')
            ->first();

        return [
            $message?->created_at ?? $laporan->created_at,
            $this->senderPayload($message?->pengirim, $laporan, $identityVisible),
            $message !== null
                ? Str::limit(Str::squish($message->isi), 120)
                : '',
        ];
    }

    /**
     * @return array{id: int, name: string, role: string}|null
     */
    private function senderPayload(?User $sender, Laporan $laporan, bool $identityVisible): ?array
    {
        if ($sender === null) {
            return null;
        }

        if (! $identityVisible && (int) $sender->id === (int) $laporan->users_id) {
            return null;
        }

        return [
            'id' => $sender->id,
            'name' => $sender->name,
            'role' => $sender->role,
        ];
    }

    private function isAfter(?CarbonInterface $time, ?CarbonInterface $readAt): bool
    {
        if ($time === null) {
            return false;
        }

        return $readAt === null || $time->gt($readAt);
    }
}
