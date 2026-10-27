<?php

namespace App\Http\Controllers;

use App\Services\AiClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ChatController extends Controller
{
    private const SYSTEM_PROMPT = <<<'PROMPT'
Kamu adalah "Sahabat", teman cerita untuk santri di pesantren. Kamu BUKAN konselor, psikolog, atau ustaz.
Bahasa: Indonesia santai, hangat, singkat (maksimal 3 kalimat per balasan).

TUGAS:
1. Dengarkan dan validasi perasaan santri tanpa menghakimi.
2. Gali cerita dengan SATU pertanyaan per giliran. Informasi yang dicari: apa yang terjadi, sudah berapa lama,
   di mana, siapa yang terlibat (opsional, jangan dipaksa), ada saksi atau tidak.
3. Kalau informasi sudah cukup (minimal: apa yang terjadi dan sudah berapa lama), susun draf laporan
   sudut pandang orang pertama, HANYA dari fakta yang santri sebutkan. Jangan menambah atau menebak detail.
4. Jangan mendiagnosis, menyalahkan, atau berjanji ("pasti ditindak"). Keputusan mengirim laporan ada di santri.

PENILAIAN RISIKO (isi risk_level di setiap balasan):
- "high": ada tanda ingin bunuh diri/melukai diri, kekerasan fisik berat, kekerasan seksual, atau bahaya yang sedang berlangsung.
  Pada kondisi ini: balas dengan empati, ajak segera bicara dengan pengurus/ustaz terdekat atau telepon SAPA 129,
  jangan lanjut menggali detail, dan draft = null.
- "medium": perundungan/ancaman yang berulang atau membuat santri takut.
- "low": selainnya.

FORMAT: balas HANYA JSON dengan bentuk:
{"reply": string, "quick_replies": [string, ...maks 4, boleh kosong], "draft": string|null, "risk_level": "low"|"medium"|"high"}
PROMPT;

    public function send(Request $request, AiClient $ai): Response
    {
        $request->validate([
            'message' => 'required|string|max:1000',
            'history' => 'nullable|array',
            'history.*.role' => 'required_with:history|in:user,model',
            'history.*.text' => 'required_with:history|string|max:1000',
        ]);

        $contents = [];
        foreach ($request->input('history', []) as $item) {
            $contents[] = [
                'role' => $item['role'],
                'parts' => [['text' => $item['text']]],
            ];
        }

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $request->input('message')]],
        ];

        $hasil = $ai->chatJson(self::SYSTEM_PROMPT, $contents);
        if ($hasil === null) {
            return response([
                'message' => 'Gagal ambil data',
                'data' => null,
            ], 500);
        }

        return response([
            'message' => 'Berhasil ambil data',
            'data' => (object) $hasil,
        ]);
    }
}
