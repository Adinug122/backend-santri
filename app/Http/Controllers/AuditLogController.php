<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $role = Auth::user()?->role;
        if (! in_array($role, ['pengawas', 'pimpinan', 'pengurus'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'action' => ['sometimes', 'string', 'max:60'],
            'actor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'subject_type' => ['sometimes', 'string', 'max:255'],
            'subject_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditLog::query()->with('actor:id,name,email,role');
        if ($role === 'pengurus') {
            $query->where('actor_id', Auth::id());
        } elseif ($role === 'pimpinan') {
            $query->where(function ($scope): void {
                $scope->where('actor_id', Auth::id())
                    ->orWhere('action', 'like', 'account.%')
                    ->orWhere('action', 'like', 'deactivation.%')
                    ->orWhereHas('actor', fn ($actor) => $actor->where('role', 'pengurus'));
            });
        }

        foreach (['action', 'actor_id', 'subject_type', 'subject_id'] as $filter) {
            if (isset($validated[$filter])) {
                $query->where($filter, $validated[$filter]);
            }
        }

        if (isset($validated['from'])) {
            $query->where('created_at', '>=', Carbon::parse($validated['from'])->startOfDay());
        }

        if (isset($validated['to'])) {
            $query->where('created_at', '<=', Carbon::parse($validated['to'])->endOfDay());
        }

        $logs = $query->latest('id')->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'data' => $this->presentLogs($logs->items(), $role),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
                'from' => $logs->firstItem(),
                'to' => $logs->lastItem(),
            ],
            'message' => 'Log audit berhasil diambil',
        ]);
    }

    /**
     * Selain pengawas, penanda laporan sensitif dan alamat IP tidak dikirim.
     *
     * @param  array<int, AuditLog>  $logs
     * @return array<int, AuditLog>
     */
    private function presentLogs(array $logs, string $role): array
    {
        if ($role === 'pengawas') {
            return $logs;
        }

        return array_map(function (AuditLog $log): AuditLog {
            $metadata = $log->metadata ?? [];
            unset($metadata['menyangkut_pimpinan']);
            $log->metadata = $metadata === [] ? null : $metadata;
            $log->makeHidden('ip_address');

            return $log;
        }, $logs);
    }
}
