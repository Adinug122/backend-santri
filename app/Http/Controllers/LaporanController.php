<?php

namespace App\Http\Controllers;

use App\Models\Catatan;
use App\Models\IdentityRequest;
use App\Models\Laporan;
use App\Models\Percakapan;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function Laravel\Prompts\error;

class LaporanController extends Controller
{
    private function hasStaffAccess(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, ['pengurus', 'pimpinan'], true);
    }

    private function canAccessReport(Laporan $laporan): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($this->hasStaffAccess()) {
            return true;
        }

        return (int) $laporan->users_id === (int) $user->id;
    }

    private function maskedUserPayload(Laporan $laporan): array
    {
        $user = $laporan->user;
        $isOwner = (int) $laporan->users_id === (int) Auth::id();

        return [
            'name' => $laporan->tampilkan_identitas || $isOwner
                ? ($user?->name ?? 'Anonymous')
                : 'Anonymous',
        ];
    }

    private function reportPayload(Laporan $laporan, bool $includeInternalNotes): array
    {
        $laporan->loadMissing([
            'user',
            'latestIdentityRequest',
            'percakapans.pengirim',
            ...($includeInternalNotes ? ['catatans.user'] : []),
        ]);

        $payload = $laporan->toArray();
        unset($payload['latest_identity_request']);
        $payload['user'] = $this->maskedUserPayload($laporan);
        $payload['permintaan_identitas'] = $this->identityRequestPayload($laporan->latestIdentityRequest);
        $ownerId = (int) $laporan->users_id;
        $identityVisible = $laporan->tampilkan_identitas
            || $ownerId === (int) Auth::id();

        $payload['percakapans'] = $laporan->percakapans
            ->map(fn (Percakapan $message) => $this->messagePayload($message, $laporan, $identityVisible))
            ->values()
            ->all();

        if (! $includeInternalNotes) {
            unset($payload['catatans']);
        }

        return $payload;
    }

    private function identityRequestPayload(?IdentityRequest $identityRequest): ?array
    {
        if (! $identityRequest) {
            return null;
        }

        return [
            'status' => $identityRequest->status,
            'alasan' => $identityRequest->alasan,
            'bukti_url' => Storage::disk('local')->temporaryUrl(
                $identityRequest->bukti_path,
                now()->addMinutes(15),
            ),
            'waktu' => $identityRequest->created_at,
        ];
    }

    private function messagePayload(
        Percakapan $message,
        Laporan $laporan,
        bool $identityVisible = false,
    ): array {
        $sender = $message->pengirim;
        $isReportOwner = (int) $sender?->id === (int) $laporan->users_id;

        return [
            'id' => $message->id,
            'isi' => $message->isi,
            'laporan_id' => $message->laporan_id,
            'pengirim' => [
                'name' => $isReportOwner && ! $identityVisible
                    ? 'Anonymous'
                    : ($sender?->name ?? 'Unknown'),
            ],
            'created_at' => $message->created_at,
            'updated_at' => $message->updated_at,
        ];
    }

    public function index()
    {
        if (! $this->hasStaffAccess()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $laporans = Laporan::with(['user', 'catatans.user', 'latestIdentityRequest', 'percakapans.pengirim'])
            ->latest()
            ->get()
            ->map(fn (Laporan $laporan) => $this->reportPayload($laporan, true));

        return response()->json([
            'data' => $laporans,
            'message' => 'Daftar laporan berhasil diambil',
        ]);
    }

    public function store(Request $request)
    {
        if (Auth::user()?->role !== 'santri') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'kategori' => ['required', 'string', 'max:255'],
            'isi' => ['required', 'string', 'max:10000'],
            'tampilkan_identitas' => ['nullable', 'boolean'],
        ]);

        try {
            $laporan = Laporan::create([
                'users_id' => Auth::id(),
                'no_laporan' => $this->generateNoLaporan(),
                'kategori' => $request->kategori,
                'isi' => $request->isi,
                'tampilkan_identitas' => (bool) $request->boolean('tampilkan_identitas', true),
                'status' => 'baru',
                'waktu' => now(),
            ]);

            return response()->json([
                'message' => 'Laporan berhasil dibuat',
                'laporan' => $laporan,
            ], 201);
        } catch (Exception $error) {
            // Return response gagal (500 Internal Server Error)
            return response()->json([
                'message' => 'Gagal menyimpan laporan',
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function mine()
    {
        if (Auth::user()?->role !== 'santri') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $laporans = Laporan::where('users_id', Auth::id())
            ->with(['user', 'latestIdentityRequest', 'percakapans.pengirim'])
            ->latest()
            ->get()
            ->map(fn (Laporan $laporan) => $this->reportPayload($laporan, false));

        return response()->json([
            'data' => $laporans,
            'message' => 'Daftar laporan milik santri berhasil diambil',
        ]);
    }

    public function show(Laporan $laporan)
    {
        if (! $this->canAccessReport($laporan)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $payload = $this->reportPayload($laporan, $this->hasStaffAccess());

        return response()->json([
            'data' => $payload,
            'message' => 'Detail laporan berhasil diambil',
        ]);
    }

    public function toggleIdentity(Request $request, Laporan $laporan): JsonResponse
    {
        if (Auth::user()?->role !== 'santri' || (int) $laporan->users_id !== (int) Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'tampilkan_identitas' => ['sometimes', 'required', 'boolean'],
        ]);

        $laporan->update([
            'tampilkan_identitas' => $validated['tampilkan_identitas']
                ?? ! $laporan->tampilkan_identitas,
        ]);

        return response()->json([
            'message' => 'Status tampilan identitas berhasil diubah',
            'laporan' => $this->reportPayload($laporan->fresh(), false),
        ]);
    }

    public function updateStatus(Request $request, Laporan $laporan)
    {
        if (! $this->hasStaffAccess()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => ['required', 'in:baru,diproses,selesai'],
        ]);

        $laporan->update([
            'status' => $request->status,
        ]);

        return response()->json([
            'message' => 'Status laporan berhasil diubah',
            'laporan' => $laporan->fresh(),
        ]);
    }

    public function escalate(Request $request, Laporan $laporan)
    {
        if (! $this->hasStaffAccess()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'tujuan' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $note = trim(sprintf('Eskalasi ke %s%s', $request->tujuan, $request->keterangan ? ': '.$request->keterangan : ''));

        Catatan::create([
            'catatan' => $note,
            'users_id' => Auth::id(),
            'laporan_id' => $laporan->id,
        ]);

        $laporan->update(['status' => 'diproses']);

        return response()->json([
            'message' => 'Eskalasi berhasil dicatat',
            'laporan' => $laporan->fresh(),
        ]);
    }

    public function messages(Laporan $laporan)
    {
        if (! $this->canAccessReport($laporan)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json([
            'data' => $laporan->percakapans()
                ->with('pengirim')
                ->latest()
                ->get()
                ->map(fn (Percakapan $message) => $this->messagePayload(
                    $message,
                    $laporan,
                    $laporan->tampilkan_identitas || (int) $laporan->users_id === (int) Auth::id(),
                )),
            'message' => 'Daftar pesan berhasil diambil',
        ]);
    }

    public function sendMessage(Request $request, Laporan $laporan)
    {
        if (! $this->canAccessReport($laporan)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'isi' => ['required', 'string'],
        ]);

        $message = Percakapan::create([
            'isi' => $request->isi,
            'pengirim_id' => Auth::id(),
            'laporan_id' => $laporan->id,
        ]);

        return response()->json([
            'message' => 'Pesan berhasil dikirim',
            'data' => $this->messagePayload(
                $message->load('pengirim'),
                $laporan,
                $laporan->tampilkan_identitas || (int) $laporan->users_id === (int) Auth::id(),
            ),
        ], 201);
    }

    public function addNote(Request $request, Laporan $laporan)
    {
        if (! $this->hasStaffAccess()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'catatan' => ['required', 'string'],
        ]);

        $note = Catatan::create([
            'catatan' => $request->catatan,
            'users_id' => Auth::id(),
            'laporan_id' => $laporan->id,
        ]);

        return response()->json([
            'message' => 'Catatan internal berhasil ditambahkan',
            'data' => $note->load('user'),
        ], 201);
    }

    private function generateNoLaporan(): string
    {
        $date = now()->format('Ymd');
        $sequence = Laporan::whereDate('waktu', now()->toDateString())->count() + 1;

        return 'LAP-'.$date.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function identityRequest(Request $request, Laporan $laporan): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'alasan' => ['required', 'string', 'max:2000'],
            'bukti' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $evidencePath = $request->file('bukti')->store('identity-requests', 'local');

        if (! $evidencePath) {
            abort(500, 'Bukti permintaan identitas gagal disimpan.');
        }

        try {
            $identityRequest = IdentityRequest::create([
                'laporan_id' => $laporan->id,
                'users_id' => Auth::id(),
                'alasan' => $validated['alasan'],
                'bukti_path' => $evidencePath,
                'status' => 'menunggu',
            ]);

            return response()->json([
                'message' => 'Permintaan identitas berhasil diajukan',
                'data' => $identityRequest,
                'laporan' => $this->reportPayload($laporan->fresh(), false),
            ], 201);

        } catch (Exception $error) {
            return response()->json([
                'message' => 'Gagal mengajukan permintaan identitas',
                'error' => $error->getMessage(),
            ], 500);
        }
    }

    public function respondToIdentityRequest(Request $request, Laporan $laporan): JsonResponse
    {
        if (
            Auth::user()?->role !== 'santri'
            || (int) $laporan->users_id !== (int) Auth::id()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:disetujui,ditolak,1,0'],
        ]);
        $identityStatus = in_array((string) $validated['status'], ['1', 'disetujui'], true)
            ? 'disetujui'
            : 'ditolak';

        try {
            DB::transaction(function () use ($laporan, $identityStatus): void {
                $identityRequest = $laporan->identityRequests()
                    ->where('status', 'menunggu')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $identityRequest) {
                    abort(404, 'Tidak ada permintaan identitas yang menunggu persetujuan.');
                }

                $identityRequest->update(['status' => $identityStatus]);
                $laporan->update([
                    'tampilkan_identitas' => $identityStatus === 'disetujui',
                ]);
            });

            return response()->json([
                'message' => $identityStatus === 'disetujui'
                    ? 'Permintaan identitas disetujui'
                    : 'Permintaan identitas ditolak',
                'data' => $this->reportPayload($laporan->fresh(), false),
            ]);
        } catch (Exception $error) {
            return response()->json([
                'message' => 'Gagal merespons permintaan identitas',
                'error' => $error->getMessage(),
            ], 500);
        }
    }
}
