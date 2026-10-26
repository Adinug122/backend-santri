<?php

namespace App\Http\Controllers;

use App\Models\AccountDeactivationRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DeactivationRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = Auth::user();

        if (! in_array($actor?->role, ['pengawas', 'pimpinan'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'in:menunggu,disetujui,ditolak'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AccountDeactivationRequest::query()
            ->with([
                'user:id,name,email,role,is_active',
                'requester:id,name,email,role',
                'decider:id,name,email,role',
            ]);

        if ($actor->role === 'pimpinan') {
            $query->where('requested_by', $actor->id);
        }

        if (isset($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        $requests = $query->latest('id')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => $requests->items(),
            'meta' => $this->meta($requests),
            'message' => 'Daftar pengajuan penonaktifan berhasil diambil',
        ]);
    }

    public function store(Request $request, User $user): JsonResponse
    {
        if (Auth::user()?->role !== 'pimpinan') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        if ((int) $user->id === (int) Auth::id()) {
            return response()->json([
                'message' => 'Akun yang sedang digunakan tidak dapat diajukan untuk dinonaktifkan.',
            ], 422);
        }

        if ($user->role === 'pengawas') {
            return response()->json([
                'message' => 'Akun pengawas tidak dapat dinonaktifkan dari aplikasi.',
            ], 422);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Akun sudah tidak aktif.'], 422);
        }

        if (! $user->hasOpenReports()) {
            return response()->json([
                'message' => 'Akun tidak memiliki laporan terbuka. Nonaktifkan langsung melalui endpoint status.',
            ], 422);
        }

        $pending = AccountDeactivationRequest::query()
            ->where('user_id', $user->id)
            ->where('status', 'menunggu')
            ->exists();

        if ($pending) {
            return response()->json([
                'message' => 'Sudah ada pengajuan penonaktifan yang menunggu keputusan pengawas.',
            ], 409);
        }

        $deactivationRequest = AccountDeactivationRequest::create([
            'user_id' => $user->id,
            'requested_by' => Auth::id(),
            'reason' => $validated['reason'],
            'status' => 'menunggu',
        ]);

        AuditLog::record(AuditLog::DEACTIVATION_REQUESTED, $deactivationRequest, [
            'target_user_id' => $user->id,
            'reason' => $validated['reason'],
        ]);

        return response()->json([
            'data' => $deactivationRequest->load(['user', 'requester']),
            'message' => 'Pengajuan penonaktifan berhasil dibuat dan menunggu persetujuan pengawas.',
        ], 201);
    }

    public function update(Request $request, AccountDeactivationRequest $deactivationRequest): JsonResponse
    {
        if (Auth::user()?->role !== 'pengawas') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'in:disetujui,ditolak'],
            'catatan' => ['nullable', 'string', 'max:1000'],
        ]);

        [$updated, $target] = DB::transaction(function () use ($deactivationRequest, $validated): array {
            $locked = AccountDeactivationRequest::query()
                ->lockForUpdate()
                ->findOrFail($deactivationRequest->id);

            if ($locked->status !== 'menunggu') {
                abort(409, 'Pengajuan sudah diputuskan.');
            }

            if ((int) $locked->requested_by === (int) Auth::id()) {
                abort(422, 'Pemohon tidak boleh menyetujui pengajuannya sendiri.');
            }

            $target = User::query()->lockForUpdate()->findOrFail($locked->user_id);

            if ($validated['status'] === 'disetujui') {
                if ($target->role === 'pengawas') {
                    abort(422, 'Akun pengawas tidak dapat dinonaktifkan dari aplikasi.');
                }

                if ($target->role === 'pimpinan' && User::query()
                    ->where('role', 'pimpinan')
                    ->where('is_active', true)
                    ->count() <= 1) {
                    abort(422, 'Pimpinan aktif terakhir tidak dapat dinonaktifkan.');
                }

                if (! $target->is_active) {
                    abort(422, 'Akun sudah tidak aktif.');
                }

                $target->is_active = false;
                $target->save();
                $target->tokens()->delete();
            }

            $locked->update([
                'status' => $validated['status'],
                'decided_by' => Auth::id(),
                'decided_at' => now(),
            ]);

            AuditLog::record(
                $validated['status'] === 'disetujui'
                    ? AuditLog::DEACTIVATION_APPROVED
                    : AuditLog::DEACTIVATION_REJECTED,
                $locked,
                [
                    'target_user_id' => $target->id,
                    'decision' => $validated['status'],
                    'catatan' => $validated['catatan'] ?? null,
                ],
            );

            if ($validated['status'] === 'disetujui') {
                AuditLog::record(AuditLog::ACCOUNT_DEACTIVATED, $target, [
                    'role' => $target->role,
                    'deactivation_request_id' => $locked->id,
                ]);
            }

            return [$locked, $target];
        });

        return response()->json([
            'data' => $updated->load(['user', 'requester', 'decider']),
            'message' => $validated['status'] === 'disetujui'
                ? 'Pengajuan disetujui dan akun berhasil dinonaktifkan.'
                : 'Pengajuan penonaktifan ditolak.',
            'target_is_active' => (bool) $target->is_active,
        ]);
    }

    /**
     * @return array{current_page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null}
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
