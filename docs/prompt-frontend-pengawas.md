# Prompt Frontend — Fitur Pengawas & Perlindungan Pelapor

Gunakan prompt di bawah ini sebagai instruksi kerja untuk membangun sisi frontend.
Backend sudah selesai; jangan mengubah kontrak API.

---

## Prompt

Kamu mengerjakan frontend untuk aplikasi pelaporan santri. Backend sudah menyediakan
peran baru `pengawas`, penanda laporan `menyangkut_pimpinan`, alur persetujuan
penonaktifan dua orang, dan audit log. Dokumentasi kontrak ada di
`docs/api-pengawas.md`; baca dokumen itu sebelum mulai.

### Konteks teknis

- Base API: `http://localhost:8000/api`
- Autentikasi: `Authorization: Bearer <token>` dari `POST /auth/login`.
- Respons login berisi `user`, `role`, `dashboard`, dan `must_change_password`.
  Gunakan `dashboard` untuk redirect setelah login. Untuk peran baru nilainya
  `/dashboard/pengawas`.
- Jangan melakukan pengecekan hak akses hanya di frontend. Backend tetap sumber
  kebenaran; frontend hanya menyembunyikan menu/aksi yang tidak relevan.
- Frontend memakai React + JSX biasa (JavaScript, bukan TypeScript). Jangan membuat
  file `.ts`/`.tsx` atau menambahkan tipe TypeScript.

### Aturan global

1. Tambahkan dukungan peran `pengawas` pada guard route dan navigasi.
2. `pimpinan` dan `pengurus` tidak boleh pernah merender laporan
   `menyangkut_pimpinan = true`, karena backend mengembalikan `403`. Tangani `403`
   sebagai "tidak berhak", bukan crash.
3. `pengawas` hanya melihat laporan `menyangkut_pimpinan = true`. Jangan tampilkan
   menu laporan umum untuknya.
4. Jangan menambahkan tombol hapus laporan. Backend tidak menyediakan hapus.
5. Jangan menambahkan pembuatan akun `pengawas` di UI. Akun itu dibuat di luar aplikasi.
6. Semua form harus menampilkan loading, error validasi per field, dan mencegah
   submit ganda.
7. Tangani `401` dengan logout dan arahkan ke halaman login. Tangani `429` pada
   login dengan pesan "terlalu banyak percobaan, coba lagi nanti".
8. Gunakan komponen, warna, tipografi, dan pola layout yang sudah ada. Jangan
   mendesain ulang design system.

### A. Formulir Buat Laporan (santri)

Tambahkan satu kontrol baru: checkbox atau switch dengan label
**"Laporan ini menyangkut pimpinan"**.

- Kirim `menyangkut_pimpinan: true` hanya jika dicentang; jika tidak, kirim `false`
  atau hilangkan field (default `false`).
- Sertakan teks bantuan singkat: laporan ini hanya akan dibaca pengawas independen,
  bukan pimpinan pondok.
- Setelah berhasil, tampilkan `no_laporan` sebagai kode pelacakan yang menonjol,
  dengan tombol "Salin kode".
- Tampilkan juga pengingat kanal luar: SAPA 129 dan lembaga eksternal yang relevan.
- Jangan menampilkan pilihan "menyangkut pimpinan" kepada selain `santri`.

### B. Daftar dan detail laporan

Daftar laporan sudah terfilter di backend. Jangan menambahkan filter peran di client.

Untuk `pengawas`:

- Halaman daftar khusus laporan sensitif, dengan tab/segmen status
  `baru`, `diproses`, `selesai`.
- Detail laporan menampilkan: kategori, isi, status, waktu, kode laporan,
  `menyangkut_pimpinan`, pesan, dan catatan internal.
- Aksi yang tersedia: ubah status (`diproses`/`selesai`), balas pesan, tambah
  catatan internal, eskalasi, dan ajukan permintaan identitas.
- Permintaan identitas: field `alasan` wajib; `bukti` opsional (jpg/jpeg/png/pdf,
  maks 10 MB); tampilkan status `menunggu`, `disetujui`, `ditolak`, atau `dicabut`.

Untuk `pimpinan`/`pengurus`:

- Tetap seperti sekarang. Jangan menampilkan atau meminta data laporan sensitif.
- Jika menerima `403`, tampilkan empty/error state yang aman tanpa membocorkan isi.

Untuk santri:

- Tampilkan `no_laporan` pada kartu laporan dan halaman detail.
- Tampilkan status permintaan identitas (`permintaan_identitas`) dengan aksi
  setujui/tolak bila `menunggu`.
- Tetap sediakan aksi mencabut izin identitas (`PATCH /reports/{id}/identity`).

Notifikasi:

- Gunakan `GET /notifications/summary` untuk badge dan
  `GET /notifications?page=&per_page=` untuk daftar.
- Polling ringan boleh memakai `ETag`/`If-None-Match`; hentikan polling saat tab
  tidak aktif.
- Item notifikasi sudah terfilter per peran.

### C. Dashboard Pengawas

Buat route `/dashboard/pengawas` dan halaman dengan tiga bagian:

1. **Ringkasan** — ambil `GET /dashboard/summary`. Tampilkan kartu
   `total_laporan`, `laporan_per_status` (`baru`, `diproses`, `selesai`), dan
   sebaran kategori. Ingat: angka untuk pengawas hanya laporan sensitif.
2. **Laporan sensitif** — daftar laporan `menyangkut_pimpinan` dengan aksi menuju detail.
3. **Persetujuan penonaktifan** — daftar pengajuan `status=menunggu` beserta
   nama akun target, alasan, pemohon, dan tanggal. Sediakan aksi setujui/tolak
   dengan konfirmasi dan field catatan opsional.

Navigasi Pengawas minimal: Ringkasan, Laporan Sensitif, Persetujuan Penonaktifan,
Audit Log. Jangan tampilkan menu kelola akun, impor santri, atau buat laporan.

### D. Manajemen akun (pimpinan) + aturan dua orang

Di halaman daftar akun:

- Tombol nonaktifkan untuk akun `pengawas` harus disembunyikan atau disabled
  dengan penjelasan "Akun pengawas tidak dapat dinonaktifkan dari aplikasi".
- Saat memanggil `PATCH /admin/accounts/{id}/status` dan menerima `422` dengan
  `requires_approval: true`, jangan tampilkan error mentah. Buka modal
  **"Ajukan penonaktifan ke Pengawas"**:
  - field alasan wajib (maks 1000 karakter),
  - kirim `POST /admin/accounts/{id}/deactivation-requests`,
  - setelah sukses, tampilkan status "Menunggu persetujuan pengawas" pada akun
    tersebut dan muat ulang daftar pengajuan.
- Tampilkan daftar pengajuan milik pimpinan (`GET /admin/deactivation-requests`)
  beserta status `menunggu`, `disetujui`, `ditolak`.
- Jangan menyediakan tombol menyetujui pengajuan untuk pimpinan.

### E. Audit log (pengawas)

Buat halaman audit log dengan:

- Tabel: waktu, pelaku (`actor.name` + role), aksi, objek
  (`subject_type` + `subject_id`), metadata ringkas, IP.
- Filter: `action`, `actor_id`, `from`, `to`; pagination `per_page`.
- Detail metadata ditampilkan sebagai key-value, bukan JSON mentah jika memungkinkan.
- Tidak ada aksi edit/hapus. Halaman ini read-only.
- `actor` bisa `null`; tampilkan "Sistem/akun dihapus".

### F. UX dan aksesibilitas

- Gunakan label eksplisit, bukan placeholder sebagai satu-satunya label.
- Switch/checkbox "menyangkut pimpinan" harus bisa dioperasikan dengan keyboard
  dan memiliki teks bantuan yang terhubung (`aria-describedby`).
- Modal konfirmasi persetujuan penonaktifan harus menangkap fokus, bisa ditutup
  dengan Esc, dan mengembalikan fokus ke tombol pemicu.
- Status jangan dibedakan hanya dengan warna; sertakan teks.
- Semua tombol aksi memiliki state loading dan disabled saat request berjalan.
- Sediakan empty state yang jelas untuk daftar kosong dan error state dengan
  tombol "Coba lagi".

### Bentuk data (JavaScript / JSX)

Frontend memakai React dengan JSX biasa, bukan TypeScript. Jangan menambahkan file
`.ts`/`.tsx`, tipe TypeScript, atau konfigurasi TS. Gunakan JavaScript biasa dan
komponen `.jsx`.

Contoh bentuk data untuk memudahkan mapping props:

```js
// role: 'santri' | 'pengurus' | 'pimpinan' | 'pengawas'

const contohLaporan = {
  id: 12,
  no_laporan: 'LAP-20261026-0001',
  kategori: 'Perilaku pimpinan',
  isi: 'Laporan yang menyangkut pimpinan pondok.',
  tampilkan_identitas: false,
  menyangkut_pimpinan: true,
  status: 'baru', // 'baru' | 'diproses' | 'selesai'
  status_diubah: null,
  waktu: '2026-10-26T05:00:00.000000Z',
  created_at: '2026-10-26T05:00:00.000000Z',
  updated_at: '2026-10-26T05:00:00.000000Z',
  users_id: undefined, // dihilangkan backend saat identitas disembunyikan
  user: { name: 'Anonymous' },
  permintaan_identitas: null, // atau objek { status, alasan, diminta_oleh, ... }
  percakapans: [
    {
      id: 1,
      isi: 'Kami menindaklanjuti.',
      laporan_id: 12,
      pengirim: { id: 3, name: 'Pengawas Yayasan', role: 'pengawas' },
      created_at: '2026-10-26T05:00:00.000000Z',
      updated_at: '2026-10-26T05:00:00.000000Z',
    },
  ],
  catatans: [], // hanya ada untuk pengurus, pimpinan, pengawas
};

const contohDeactivationRequest = {
  id: 1,
  user_id: 4,
  requested_by: 2,
  decided_by: null,
  reason: 'Santri melanggar aturan.',
  status: 'menunggu', // 'menunggu' | 'disetujui' | 'ditolak'
  decided_at: null,
  created_at: '2026-10-26T05:10:00.000000Z',
  updated_at: '2026-10-26T05:10:00.000000Z',
  user: { id: 4, name: 'Ahmad Fauzi', email: 'ahmad@santri.test', role: 'santri', is_active: true },
  requester: { id: 2, name: 'Pimpinan Pondok', email: 'pimpinan@santri.test', role: 'pimpinan' },
  decider: null, // atau { id, name, email, role }
};

const contohAuditLog = {
  id: 25,
  actor_id: 3,
  action: 'report.status_changed',
  subject_type: 'App\\Models\\Laporan',
  subject_id: 12,
  metadata: { from: 'baru', to: 'diproses' }, // bisa null
  ip_address: '127.0.0.1',
  created_at: '2026-10-26T05:30:00.000000Z',
  actor: { id: 3, name: 'Pengawas Yayasan', email: 'pengawas@santri.test', role: 'pengawas' }, // bisa null
};
```

### Kriteria penerimaan

1. Santri dapat menandai laporan sebagai "menyangkut pimpinan" dan melihat kode pelacakan.
2. Pimpinan/pengurus tidak pernah melihat laporan sensitif, termasuk lewat URL langsung.
3. Pengawas hanya melihat laporan sensitif, dapat mengubah status, membalas pesan,
   menambah catatan, eskalasi, dan meminta identitas.
4. Pimpinan yang mencoba menonaktifkan akun dengan laporan terbuka mendapat modal
   pengajuan, bukan error mentah.
5. Pengawas dapat menyetujui/menolak pengajuan; setelah disetujui, akun target
   nonaktif dan pengajuan berubah status.
6. Pengawas dapat membuka audit log, memfilter, dan tidak menemukan aksi edit/hapus.
7. Semua state loading, kosong, `403`, `409`, dan `422` ditangani tanpa crash.
8. Tidak ada tombol hapus laporan dan tidak ada pembuatan akun pengawas di UI.

### Jangan lakukan

- Jangan mengubah backend atau kontrak API.
- Jangan menyimpan laporan sensitif di cache lokal yang dipakai lintas peran.
- Jangan mengandalkan penyembunyian menu sebagai satu-satunya kontrol akses.
- Jangan menampilkan nama pelapor anonim kecuali `user.name` bukan `"Anonymous"`.
- Jangan menambahkan fitur arsip atau hapus laporan; keduanya belum ada di backend.
