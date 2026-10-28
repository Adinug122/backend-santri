<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Catatan;
use App\Models\Laporan;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LaporanEskalasiController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const DESTINATIONS = [
        'polsek' => 'Kepolisian Sektor',
        'uppt_ppa' => 'UPT Perlindungan Perempuan dan Anak',
        'pengurus_pusat' => 'Pengurus Pusat',
    ];

    public function store(Request $request, Laporan $laporan): JsonResponse
    {
        $this->authorizePengurusReport($laporan);

        $validated = $request->validate([
            'tujuan' => ['required', 'string', 'in:polsek,uppt_ppa,pengurus_pusat'],
            'nomor_wa_tujuan' => ['required', 'string', 'max:16', 'regex:/^\+?[1-9][0-9]{7,14}$/'],
            'catatan_pengurus' => ['required', 'string', 'max:5000'],
        ], [
            'nomor_wa_tujuan.regex' => 'Nomor WhatsApp harus menggunakan format internasional, misalnya +6281234567890.',
        ]);

        $escalatedReport = DB::transaction(function () use ($laporan, $validated): Laporan {
            $lockedReport = Laporan::query()
                ->lockForUpdate()
                ->findOrFail($laporan->id);

            $this->authorizePengurusReport($lockedReport);

            if ($lockedReport->is_locked) {
                abort(409, 'Laporan telah dikunci dan sudah pernah dieskalasi.');
            }

            $destinationLabel = self::DESTINATIONS[$validated['tujuan']];
            $noteText = sprintf(
                'Eskalasi ke %s: %s',
                $destinationLabel,
                trim($validated['catatan_pengurus']),
            );
            $escalatedAt = now();

            $lockedReport->update([
                'status' => Laporan::STATUS_ESCALATED,
                'status_diubah' => $escalatedAt,
                'is_locked' => true,
            ]);

            $note = Catatan::create([
                'catatan' => $noteText,
                'users_id' => Auth::id(),
                'laporan_id' => $lockedReport->id,
            ]);

            AuditLog::record(AuditLog::REPORT_ESCALATED, $lockedReport, [
                'tujuan' => $validated['tujuan'],
                'nomor_wa_tujuan' => $validated['nomor_wa_tujuan'],
                'catatan_pengurus' => trim($validated['catatan_pengurus']),
                'catatan_id' => $note->id,
                'escalated_at' => $escalatedAt->toIso8601String(),
            ]);

            return $lockedReport;
        });

        $destinationLabel = self::DESTINATIONS[$validated['tujuan']];
        $phoneNumber = (string) preg_replace('/\D+/', '', $validated['nomor_wa_tujuan']);
        $whatsAppMessage = sprintf(
            'Yth. %s, kami menyampaikan laporan nomor %s dari Sahabat Pesantren. Kategori: %s. Ringkasan: "%s". Catatan pengurus: %s',
            $destinationLabel,
            $escalatedReport->no_laporan,
            $escalatedReport->kategori,
            Str::limit($escalatedReport->isi, 300),
            trim($validated['catatan_pengurus']),
        );

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil dieskalasi dan dikunci.',
            'whatsapp_url' => 'https://wa.me/'.$phoneNumber.'?text='.rawurlencode($whatsAppMessage),
            'pdf_download_url' => route('reports.escalation.pdf', ['laporan' => $escalatedReport->id]),
        ]);
    }

    public function download(Laporan $laporan): Response
    {
        $this->authorizePengurusReport($laporan);

        abort_unless(
            $laporan->is_locked && $laporan->status === Laporan::STATUS_ESCALATED,
            404,
            'Surat pengantar belum tersedia.',
        );

        $auditLog = AuditLog::query()
            ->with('actor')
            ->where('action', AuditLog::REPORT_ESCALATED)
            ->where('subject_type', $laporan->getMorphClass())
            ->where('subject_id', $laporan->id)
            ->latest('id')
            ->firstOrFail();
        $metadata = $auditLog->metadata ?? [];
        $tujuan = $metadata['tujuan'] ?? '';

        return Pdf::loadView('pdf.surat-pengantar', [
            'laporan' => $laporan->loadMissing('user'),
            'tujuan' => self::DESTINATIONS[$tujuan] ?? $tujuan,
            'catatanPengurus' => $metadata['catatan_pengurus'] ?? '',
            'namaPengurus' => $auditLog->actor?->name ?? 'Pengurus',
            'tanggalEskalasi' => $auditLog->created_at,
            'namaSantri' => $laporan->tampilkan_identitas
                ? ($laporan->user?->name ?? 'Anonim')
                : 'Anonim',
        ])
            ->setPaper('a4')
            ->download('surat-pengantar-'.$laporan->no_laporan.'.pdf');
    }

    private function authorizePengurusReport(Laporan $laporan): void
    {
        if (
            Auth::user()?->role !== 'pengurus'
            || ! Laporan::query()
                ->visibleToRole('pengurus')
                ->whereKey($laporan->getKey())
                ->exists()
        ) {
            abort(403, 'Unauthorized');
        }
    }
}
