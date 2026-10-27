<?php

namespace App\Http\Controllers;

use App\Models\Laporan;
use App\Services\AiClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class DashboardAiController extends Controller
{
    private const MAX_REPORTS = 30;

    private const MAX_CHARS = 300;

    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu membantu pengurus pesantren membaca laporan santri dengan cepat.
Kamu menerima daftar laporan (tanggal, kategori, status, isi singkat).
Rangkum polanya dalam bahasa Indonesia yang singkat dan netral.
ATURAN:
- Gunakan hanya fakta yang ada di daftar. Jangan menebak, jangan menambah detail.
- Jangan menyebut atau menebak nama orang.
- Jangan menyarankan hukuman atau tindakan terhadap seseorang.
- Kalau ada laporan yang menyebut bahaya (melukai diri, kekerasan, ancaman),
  tulis itu sebagai poin pertama dengan kalimat yang jelas.
FORMAT: balas HANYA JSON:
{"ringkasan": "2-3 kalimat", "poin": ["maksimal 4 poin singkat"]}
PROMPT;

    public function summary(Request $request, AiClient $ai): JsonResponse
    {
        $role = Auth::user()?->role;
        if (! in_array($role, ['pengurus', 'pimpinan', 'pengawas'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'periode' => ['nullable', 'integer', 'in:7,30'],
            'refresh' => ['nullable', 'boolean'],
        ]);
        $days = (int) ($data['periode'] ?? 7);
        $key = "dashboard-ai:{$role}:{$days}";

        if ($request->boolean('refresh')) {
            Cache::forget($key);
        }

        $result = Cache::get($key);
        $fromCache = $result !== null;

        if ($result === null) {
            $result = $this->buildSummary($ai, $role, $days);

            if ($result === null) {
                return response()->json([
                    'message' => 'Ringkasan AI belum tersedia. Coba lagi sebentar.',
                ], 503);
            }

            Cache::put($key, $result, now()->addMinutes(10));
        }

        return response()->json([
            'data' => $result + ['dari_cache' => $fromCache],
            'message' => 'Ringkasan berhasil dibuat',
        ]);
    }

    private function buildSummary(AiClient $ai, string $role, int $days): ?array
    {
        $reports = Laporan::query()
            ->visibleToRole($role)
            ->where('created_at', '>=', now()->subDays($days))
            ->latest()
            ->limit(self::MAX_REPORTS)
            ->get();

        $base = [
            'jumlah_laporan' => $reports->count(),
            'periode_hari' => $days,
            'dibuat_pada' => now()->toIso8601String(),
        ];

        if ($reports->isEmpty()) {
            return $base + [
                'ringkasan' => 'Belum ada laporan pada periode ini.',
                'poin' => [],
            ];
        }

        $lines = $reports->values()->map(fn (Laporan $laporan, int $index) => sprintf(
            '%d. [%s] kategori=%s status=%s | %s',
            $index + 1,
            $laporan->created_at?->toDateString(),
            $laporan->kategori,
            $laporan->status,
            Str::limit(preg_replace('/\s+/', ' ', (string) $laporan->isi) ?? '', self::MAX_CHARS, ''),
        ))->implode("\n");
        $json = $ai->askJson(self::SYSTEM_PROMPT, $lines);
        $summary = $json['ringkasan'] ?? null;
        $points = $json['poin'] ?? [];

        if (! is_string($summary) || trim($summary) === '' || ! is_array($points)) {
            return null;
        }

        return $base + [
            'ringkasan' => $summary,
            'poin' => array_slice(array_values(array_filter($points, 'is_string')), 0, 4),
        ];
    }
}
