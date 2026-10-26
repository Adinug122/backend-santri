<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

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

    public function send(Request $request)
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

        $response = Http::withHeaders([
            'x-goog-api-key' => config('services.gemini.key'),
        ])->timeout(30)->post(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash:generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                'contents' => $contents,
                'generationConfig' => ['responseMimeType' => 'application/json'],
            ]
        );

        if ($this->isGeminiRateLimited($response)) {
            $messages = [[
                'role' => 'system',
                'content' => self::SYSTEM_PROMPT,
            ]];

            foreach ($contents as $content) {
                $messages[] = [
                    'role' => $content['role'] === 'model' ? 'assistant' : 'user',
                    'content' => $content['parts'][0]['text'],
                ];
            }

            $response = Http::withToken(config('services.groq.key'))
                ->timeout(30)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => 'llama-3.3-70b-versatile',
                    'messages' => $messages,
                    'response_format' => ['type' => 'json_object'],
                ]);

            $teks = $response->successful()
                ? $response->json('choices.0.message.content')
                : null;
        } else {
            $teks = $response->successful()
                ? $response->json('candidates.0.content.parts.0.text')
                : null;
        }

        if (! is_string($teks) || trim($teks) === '') {
            return response([
                'message' => 'Gagal ambil data',
                'data' => null,
            ], 500);
        }

        $hasil = json_decode($teks);

        if (! is_object($hasil)) {
            return response([
                'message' => 'Gagal ambil data',
                'data' => null,
            ], 500);
        }

        return response([
            'message' => 'Berhasil ambil data',
            'data' => $hasil,
        ]);
    }

    private function isGeminiRateLimited(Response $response): bool
    {
        return $response->status() === 429
            || ($response->status() === 403 && $response->json('error.status') === 'RESOURCE_EXHAUSTED');
    }
}
