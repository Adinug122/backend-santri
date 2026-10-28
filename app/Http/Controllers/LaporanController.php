<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Catatan;
use App\Models\IdentityRequest;
use App\Models\Laporan;
use App\Models\Percakapan;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LaporanController extends Controller
{
    private function hasStaffAccess(): bool
    {
        $role = Auth::user()?->role;

        return in_array($role, ['pengurus', 'pimpinan', 'pengawas'], true);
    }

    /**
     * Tandai laporan sebagai sudah dibaca oleh peran pengguna saat ini.
     */
    private function markAsRead(Laporan $laporan): void
    {
        $kolom = Laporan::readColumnFor(Auth::user()?->role);

        if ($kolom === null) {
            return;
        }

        Laporan::whereKey($laporan->id)->toBase()->update([$kolom => now()]);
    }

    private function canAccessReport(Laporan $laporan): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->role === 'pimpinan') {
            return ! $laporan->isMenyangkutPimpinan();
        }

        if ($user->role === 'pengurus') {
            return ! $laporan->isMenyangkutPimpinan()
                && ! $laporan->isRestrictedForPengurus();
        }

        if ($user->role === 'pengawas') {
            return $laporan->isMenyangkutPimpinan();
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
            'latestIdentityRequest.user',
            'percakapans.pengirim',
            ...($includeInternalNotes ? ['catatans.user'] : []),
        ]);

        $ownerId = (int) $laporan->users_id;
        $identityVisible = $laporan->tampilkan_identitas
            || $ownerId === (int) Auth::id();

        $payload = $laporan->toArray();
        unset($payload['latest_identity_request']);
        if (
            ! $laporan->isMenyangkutPimpinan()
            || Auth::user()?->role === 'pengawas'
            || (Auth::user()?->role === 'santri' && $ownerId === (int) Auth::id())
        ) {
            $payload['terlambat'] = $laporan->terlambat;
        }

        if (! in_array(Auth::user()?->role, ['pengawas', 'santri'], true)) {
            unset($payload['menyangkut_pimpinan']);
        }
        $payload['user'] = $this->maskedUserPayload($laporan);
        $payload['permintaan_identitas'] = Auth::user()?->role === 'pengurus'
            ? null
            : $this->identityRequestPayload($laporan->latestIdentityRequest);

        if (! $identityVisible) {
            unset($payload['users_id']);
        }

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
            'diminta_oleh' => $identityRequest->user?->name,
            'bukti_nama' => $identityRequest->bukti_path
                ? basename($identityRequest->bukti_path)
                : null,
            'bukti_url' => $identityRequest->bukti_path
                ? Storage::disk('local')->temporaryUrl(
                    $identityRequest->bukti_path,
                    now()->addMinutes(15),
                )
                : null,
            'dibuat' => $identityRequest->created_at,
            'dijawab' => $identityRequest->dijawab,
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
                'id' => $sender?->id,
                'name' => $isReportOwner && ! $identityVisible
                    ? 'Anonymous'
                    : ($sender?->name ?? 'Unknown'),
                'role' => $sender?->role,
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

        $laporans = Laporan::query()
            ->visibleToRole(Auth::user()?->role)
            ->with(['user', 'catatans.user', 'latestIdentityRequest', 'percakapans.pengirim'])
            ->orderByRaw(
                'CASE WHEN status = ? AND created_at <= ? THEN 0 ELSE 1 END',
                ['baru', now()->subHours(Laporan::LATE_AFTER_HOURS)],
            )
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
            'menyangkut_pimpinan' => ['nullable', 'boolean'],
        ]);

        try {
            $laporan = Laporan::create([
                'users_id' => Auth::id(),
                'no_laporan' => $this->generateNoLaporan(),
                'kategori' => $request->kategori,
                'isi' => $request->isi,
                'tampilkan_identitas' => (bool) $request->boolean('tampilkan_identitas', true),
                'menyangkut_pimpinan' => (bool) $request->boolean('menyangkut_pimpinan', false),
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

    public function destroy(Laporan $laporan): JsonResponse
    {
        if (Auth::user()?->role !== 'santri' || (int) $laporan->users_id !== (int) Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($laporan->status !== 'baru') {
            return response()->json([
                'message' => 'Laporan hanya dapat dihapus saat status masih baru',
            ], 409);
        }

        $buktiPaths = $laporan->identityRequests()
            ->whereNotNull('bukti_path')
            ->pluck('bukti_path')
            ->all();

        DB::transaction(function () use ($laporan): void {
            AuditLog::record(AuditLog::REPORT_DELETED, $laporan, [
                'no_laporan' => $laporan->no_laporan,
            ]);

            $laporan->delete();
        });

        if ($buktiPaths !== [] && ! Storage::disk('local')->delete($buktiPaths)) {
            Log::warning('Failed to remove report identity evidence after deleting the report.', [
                'laporan_id' => $laporan->id,
            ]);
        }

        return response()->json([
            'message' => 'Laporan berhasil dihapus',
        ]);
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

        $this->markAsRead($laporan);

        if ($this->hasStaffAccess()) {
            AuditLog::record(AuditLog::REPORT_VIEWED, $laporan, [
                'role' => Auth::user()?->role,
                'menyangkut_pimpinan' => $laporan->isMenyangkutPimpinan(),
            ]);
        }

        return response()->json([
            'data' => $this->reportPayload($laporan->fresh(), $this->hasStaffAccess()),
            'message' => 'Detail laporan berhasil diambil',
        ]);
    }

    public function trail(Laporan $laporan): JsonResponse
    {
        if (Auth::user()?->role !== 'santri' || (int) $laporan->users_id !== (int) Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $labels = [
            AuditLog::REPORT_VIEWED => 'dibuka',
            AuditLog::REPORT_STATUS_CHANGED => 'status_diubah',
            AuditLog::REPORT_MESSAGE_SENT => 'dibalas',
            AuditLog::REPORT_ESCALATED => 'diteruskan',
            AuditLog::IDENTITY_REQUESTED => 'minta_nama',
            AuditLog::IDENTITY_RESPONDED => 'minta_nama_dijawab',
        ];

        $items = AuditLog::query()
            ->with('actor:id,role')
            ->where('subject_type', $laporan->getMorphClass())
            ->where('subject_id', $laporan->id)
            ->whereIn('action', array_keys($labels))
            ->oldest('id')
            ->get()
            ->map(fn (AuditLog $log) => [
                'aksi' => $labels[$log->action],
                'peran' => $log->actor?->role,
                'tanggal' => $log->created_at?->toDateString(),
                'status' => $log->metadata['to'] ?? null,
            ])
            ->unique(fn (array $item) => implode('|', $item))
            ->values();

        return response()->json([
            'data' => $items,
            'message' => 'Jejak laporan berhasil diambil',
        ]);
    }

    public function toggleIdentity(Request $request, Laporan $laporan): JsonResponse
    {
        if (Auth::user()?->role !== 'santri' || (int) $laporan->users_id !== (int) Auth::id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'tampilkan_identitas' => ['sometimes', 'required', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $laporan): void {
            $lockedLaporan = Laporan::query()->lockForUpdate()->findOrFail($laporan->id);

            $tampilkanIdentitas = $request->has('tampilkan_identitas')
                ? $request->boolean('tampilkan_identitas')
                : ! $lockedLaporan->tampilkan_identitas;

            $lockedLaporan->update([
                'tampilkan_identitas' => $tampilkanIdentitas,
            ]);

            $identityRequest = $lockedLaporan->identityRequests()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if (! $identityRequest) {
                return;
            }

            if (! $tampilkanIdentitas && $identityRequest->status === 'disetujui') {
                $identityRequest->update([
                    'status' => 'dicabut',
                    'dijawab' => now(),
                ]);

                return;
            }

            if ($tampilkanIdentitas && $identityRequest->status === 'dicabut') {
                $identityRequest->update([
                    'status' => 'disetujui',
                    'dijawab' => now(),
                ]);
            }
        });

        $updatedLaporan = $laporan->fresh();

        AuditLog::record(AuditLog::IDENTITY_TOGGLED, $updatedLaporan, [
            'tampilkan_identitas' => (bool) $updatedLaporan->tampilkan_identitas,
        ]);

        return response()->json([
            'message' => 'Status tampilan identitas berhasil diubah',
            'laporan' => $this->reportPayload($updatedLaporan, false),
        ]);
    }

    public function updateStatus(Request $request, Laporan $laporan)
    {
        if (! $this->hasStaffAccess() || ! $this->canAccessReport($laporan)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => ['required', 'in:diproses,selesai'],
        ]);

        if ($laporan->status !== $request->status) {
            $statusLama = $laporan->status;

            $laporan->update([
                'status_diubah' => now(),
                'status' => $request->status,
            ]);

            AuditLog::record(AuditLog::REPORT_STATUS_CHANGED, $laporan, [
                'from' => $statusLama,
                'to' => $request->status,
            ]);
        }

        return response()->json([
            'message' => 'Status laporan berhasil diubah',
            'laporan' => $this->reportPayload($laporan->fresh(), true),
        ]);
    }

    public function escalate(Request $request, Laporan $laporan)
    {
        if (! $this->hasStaffAccess() || ! $this->canAccessReport($laporan)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'tujuan' => ['required', 'string', 'max:255'],
            'keterangan' => ['nullable', 'string'],
        ]);

        $note = Catatan::create([
            'catatan' => trim(sprintf('Eskalasi ke %s%s', $request->tujuan, $request->keterangan ? ': '.$request->keterangan : '')),
            'users_id' => Auth::id(),
            'laporan_id' => $laporan->id,
        ]);

        $laporan->update(['status' => 'diproses', 'status_diubah' => now()]);

        AuditLog::record(AuditLog::REPORT_ESCALATED, $laporan, [
            'tujuan' => $request->tujuan,
            'catatan_id' => $note->id,
        ]);

        return response()->json([
            'message' => 'Eskalasi berhasil dicatat',
            'laporan' => $this->reportPayload($laporan->fresh(), true),
        ]);
    }

    public function messages(Laporan $laporan)
    {
        if (! $this->canAccessReport($laporan)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->markAsRead($laporan);

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

        if ($this->hasStaffAccess()) {
            AuditLog::record(AuditLog::REPORT_MESSAGE_SENT, $laporan, [
                'message_id' => $message->id,
                'role' => Auth::user()?->role,
            ]);
        }

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
        if (! $this->hasStaffAccess() || ! $this->canAccessReport($laporan)) {
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

        AuditLog::record(AuditLog::REPORT_NOTE_ADDED, $laporan, [
            'catatan_id' => $note->id,
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
        $user = Auth::user();

        if (
            ! in_array($user?->role, ['pimpinan', 'pengawas'], true)
            || ! $this->canAccessReport($laporan)
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $latestIdentityRequest = $laporan->latestIdentityRequest;

        if (in_array($latestIdentityRequest?->status, ['menunggu', 'disetujui'], true)) {
            return response()->json([
                'message' => $latestIdentityRequest->status === 'menunggu'
                    ? 'Permintaan untuk laporan ini masih menunggu jawaban santri.'
                    : 'Santri sudah menyetujui permintaan untuk laporan ini.',
            ], 409);
        }

        $validated = $request->validate([
            'alasan' => ['required', 'string', 'max:2000'],
            'bukti' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);

        $evidencePath = null;

        if ($request->hasFile('bukti')) {
            $evidencePath = $request->file('bukti')->store('identity-requests', 'local');

            if (! $evidencePath) {
                abort(500, 'Bukti permintaan identitas gagal disimpan.');
            }
        }

        try {
            $identityRequest = IdentityRequest::create([
                'laporan_id' => $laporan->id,
                'users_id' => Auth::id(),
                'alasan' => $validated['alasan'],
                'bukti_path' => $evidencePath,
                'status' => 'menunggu',
            ]);

            AuditLog::record(AuditLog::IDENTITY_REQUESTED, $laporan, [
                'identity_request_id' => $identityRequest->id,
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

                $identityRequest->update([
                    'status' => $identityStatus,
                    'dijawab' => now(),
                ]);
                $laporan->update([
                    'tampilkan_identitas' => $identityStatus === 'disetujui',
                ]);

                AuditLog::record(AuditLog::IDENTITY_RESPONDED, $laporan, [
                    'identity_request_id' => $identityRequest->id,
                    'status' => $identityStatus,
                ]);
            });

            return response()->json([
                'message' => $identityStatus === 'disetujui'
                    ? 'Permintaan identitas disetujui'
                    : 'Permintaan identitas ditolak',
                'data' => $this->reportPayload($laporan->fresh(), false),
            ]);
        } catch (HttpException $error) {
            throw $error;
        } catch (Exception $error) {
            return response()->json([
                'message' => 'Gagal merespons permintaan identitas',
                'error' => $error->getMessage(),
            ], 500);
        }
    }
}
