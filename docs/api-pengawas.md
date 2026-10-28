# API — Peran Pengawas, Laporan Sensitif, Persetujuan Penonaktifan, dan Audit

Dokumen ini melengkapi `README.md` dan hanya mencakup permukaan API yang berubah
atau bertambah pada fitur tata kelola perlindungan pelapor.

- Base URL lokal: `http://localhost:8000/api`
- Format: JSON
- Autentikasi: `Authorization: Bearer <token>` dari `POST /auth/login`
- Semua endpoint di dokumen ini memakai middleware `auth:sanctum` dan
  `EnsurePasswordChanged`.

## 1. Peran

| Peran | Deskripsi |
|---|---|
| `santri` | Membuat laporan dan melihat laporan miliknya. |
| `pengurus` | Menangani laporan umum; tidak melihat laporan `masalah_pengurus` maupun laporan `menyangkut_pimpinan`. |
| `pimpinan` | Mengelola akun dan menangani laporan umum; **tidak** dapat mengakses laporan `menyangkut_pimpinan`. |
| `pengawas` | Peran independen. Hanya melihat/menangani laporan `menyangkut_pimpinan`, menyetujui penonaktifan akun, dan membaca audit log. Tidak mengelola akun dan tidak membuat laporan. |

Akun `pengawas` sengaja tidak dapat dibuat lewat API. Akun dibuat lewat seeder atau
langsung di database. Seeder lokal menyediakan:

```
email: pengawas@santri.test
password: password
dashboard: /dashboard/pengawas
```

## 2. Format error

Error umum:

```json
{ "message": "Unauthorized" }
```

Error validasi `422`:

```json
{
  "message": "The reason field is required.",
  "errors": {
    "reason": ["The reason field is required."]
  }
}
```

Kode yang perlu ditangani frontend:

| Kode | Arti |
|---|---|
| `401` | Token tidak ada/kadaluarsa. |
| `403` | Peran tidak berhak atas laporan/aksi tersebut. |
| `404` | Resource atau route tidak ada. |
| `409` | Konflik: pengajuan duplikat atau sudah diputuskan. |
| `422` | Validasi gagal atau aksi ditolak aturan bisnis. |
| `429` | Rate limit login (`5` percobaan/menit). |

## 3. Membuat laporan

`POST /reports` — hanya `santri`.

Body:

```json
{
  "kategori": "Perilaku pimpinan",
  "isi": "Laporan yang menyangkut pimpinan pondok.",
  "tampilkan_identitas": false,
  "menyangkut_pimpinan": true
}
```

| Field | Tipe | Wajib | Catatan |
|---|---|---|---|
| `kategori` | string, max 255 | ya | Kategori bebas. |
| `isi` | string, max 10000 | ya | Isi laporan. |
| `tampilkan_identitas` | boolean | tidak | Default `true`. |
| `menyangkut_pimpinan` | boolean | tidak | Default `false`. Jika `true`, laporan hanya terlihat pemilik dan `pengawas`. |

Respons `201`:

```json
{
  "message": "Laporan berhasil dibuat",
  "laporan": {
    "id": 12,
    "users_id": 4,
    "no_laporan": "LAP-20261026-0001",
    "kategori": "Perilaku pimpinan",
    "isi": "Laporan yang menyangkut pimpinan pondok.",
    "tampilkan_identitas": false,
    "menyangkut_pimpinan": true,
    "status": "baru",
    "waktu": "2026-10-26T05:00:00.000000Z",
    "updated_at": "2026-10-26T05:00:00.000000Z",
    "created_at": "2026-10-26T05:00:00.000000Z"
  }
}
```

`no_laporan` adalah kode pelacakan yang harus ditampilkan ke santri.

Sebelum laporan dikirim, santri dapat meminta AI meringkas isi draft:

`POST /reports/summarize` — hanya `santri`; endpoint ini tidak menyimpan laporan.

Body:

```json
{
  "isi": "Teks laporan yang akan diringkas."
}
```

Respons `200` mengembalikan teks yang siap ditampilkan pada draft tambah laporan:

```json
{
  "message": "Berhasil merangkum laporan",
  "data": {
    "draft": "Isi laporan yang sudah diringkas."
  }
}
```

Setelah draft dikonfirmasi santri, kirim ke `POST /reports` untuk menyimpan laporan.

## 4. Visibilitas laporan

Berlaku untuk daftar, detail, pesan, status, catatan, eskalasi, dan permintaan identitas.

| Peran | Bisa mengakses |
|---|---|
| `santri` | Hanya laporan miliknya (`users_id` sendiri). |
| `pengurus` | Laporan dengan `menyangkut_pimpinan = false` **dan** kategori bukan `masalah_pengurus`. |
| `pimpinan` | Laporan dengan `menyangkut_pimpinan = false`. |
| `pengawas` | Laporan dengan `menyangkut_pimpinan = true`. |

`pimpinan` dan `pengurus` menerima `403` untuk seluruh endpoint laporan yang
`menyangkut_pimpinan = true`. `pengawas` menerima `403` untuk laporan biasa.

Daftar laporan hanya memuat laporan yang boleh diakses peran tersebut; tidak ada
penyaringan tambahan di frontend.

### Payload laporan

Contoh `GET /reports/{id}` untuk `pengawas`:

```json
{
  "data": {
    "id": 12,
    "no_laporan": "LAP-20261026-0001",
    "kategori": "Perilaku pimpinan",
    "isi": "Laporan yang menyangkut pimpinan pondok.",
    "tampilkan_identitas": false,
    "menyangkut_pimpinan": true,
    "status": "baru",
    "status_diubah": null,
    "santri_dibaca": null,
    "pengurus_dibaca": null,
    "pimpinan_dibaca": null,
    "pengawas_dibaca": "2026-10-26T05:05:00.000000Z",
    "waktu": "2026-10-26T05:00:00.000000Z",
    "created_at": "2026-10-26T05:00:00.000000Z",
    "updated_at": "2026-10-26T05:00:00.000000Z",
    "user": { "name": "Anonymous" },
    "permintaan_identitas": null,
    "percakapans": [],
    "catatans": []
  },
  "message": "Detail laporan berhasil diambil"
}
```

Catatan payload:

- `users_id` dihilangkan jika identitas disembunyikan dan viewer bukan pemilik.
- `user.name` bernilai `"Anonymous"` selama identitas disembunyikan.
- `permintaan_identitas` selalu `null` untuk `pengurus`; `pimpinan` dan `pengawas` menerima detailnya.
- `catatans` hanya ada untuk `pengurus`, `pimpinan`, dan `pengawas` (catatan internal).
- `percakapans[]` berisi `id`, `isi`, `laporan_id`, `pengirim { id, name, role }`, `created_at`, `updated_at`.

## 5. Notifikasi

`GET /notifications/summary` dan `GET /notifications` otomatis mengikuti visibilitas peran.

- `pimpinan` tidak menerima notifikasi laporan `menyangkut_pimpinan`.
- `pengawas` hanya menerima notifikasi laporan `menyangkut_pimpinan`.
- Penanda dibaca terpisah: `santri_dibaca`, `pengurus_dibaca`, `pimpinan_dibaca`, `pengawas_dibaca`.
- Membuka detail laporan atau daftar pesan menandai laporan sebagai dibaca untuk peran tersebut.
- `GET /notifications/summary` mengembalikan `ETag`; kirim kembali sebagai `If-None-Match` untuk menerima `304`.

Contoh ringkasan:

```json
{
  "message": "Berhasil mengambil notifikasi.",
  "code": "NOTIFICATION_FETCH_SUCCESS",
  "data": {
    "laporan_baru": 1,
    "pesan_baru": 0,
    "pembaruan": 1
  }
}
```

## 6. Ringkasan dashboard

`GET /dashboard/summary` — `pimpinan` dan `pengawas`.

Angka laporan mengikuti visibilitas peran. `pimpinan` menghitung laporan non-flagged;
`pengawas` hanya menghitung laporan flagged. `total_santri` tetap menghitung seluruh santri.

```json
{
  "data": {
    "total_santri": 120,
    "total_laporan": 8,
    "jumlah_terlambat": 2,
    "laporan_per_status": { "baru": 3, "diproses": 4, "selesai": 1 },
    "laporan_per_kategori": [
      { "kategori": "Perilaku pimpinan", "total": 8 }
    ]
  },
  "message": "Ringkasan dashboard berhasil diambil"
}
```

`jumlah_terlambat` menghitung laporan dengan status `baru` yang dibuat setidaknya
24 jam lalu. Nilainya dihitung saat endpoint dipanggil dan mengikuti visibilitas
peran; status selain `baru` tidak dihitung.

Field `terlambat` juga disertakan pada daftar dan detail laporan yang dapat
diakses. Laporan yang menyangkut pimpinan hanya mengirim field tersebut kepada
santri pemilik dan pengawas.

## 7. Menonaktifkan akun

### 7.1 Jalur langsung

`PATCH /admin/accounts/{userId}/status` — hanya `pimpinan`. Endpoint ini tetap
melakukan toggle aktivasi/nonaktivasi untuk akun tanpa laporan terbuka.

Aturan:

- Tidak bisa menonaktifkan akun sendiri.
- Tidak bisa menonaktifkan pimpinan aktif terakhir.
- Tidak bisa mengubah status akun `pengawas`.
- Jika akun masih memiliki laporan dengan `status != selesai`, permintaan ditolak
  dan harus memakai jalur persetujuan.

Respons `422` saat butuh persetujuan:

```json
{
  "message": "Akun masih memiliki laporan terbuka. Nonaktifkan melalui pengajuan persetujuan pengawas.",
  "requires_approval": true
}
```

Respons sukses:

```json
{
  "data": {
    "id": 4,
    "name": "Ahmad Fauzi",
    "email": "ahmad@santri.test",
    "role": "santri",
    "is_active": false
  },
  "message": "Akun berhasil dinonaktifkan."
}
```

### 7.2 Mengajukan persetujuan

`POST /admin/accounts/{userId}/deactivation-requests` — hanya `pimpinan`.

```json
{ "reason": "Santri melanggar aturan dan menolak pembinaan." }
```

Respons `201`:

```json
{
  "data": {
    "id": 1,
    "user_id": 4,
    "requested_by": 2,
    "decided_by": null,
    "reason": "Santri melanggar aturan dan menolak pembinaan.",
    "status": "menunggu",
    "decided_at": null,
    "created_at": "2026-10-26T05:10:00.000000Z",
    "updated_at": "2026-10-26T05:10:00.000000Z",
    "user": { "id": 4, "name": "Ahmad Fauzi", "email": "ahmad@santri.test", "role": "santri", "is_active": true },
    "requester": { "id": 2, "name": "Pimpinan Pondok", "email": "pimpinan@santri.test", "role": "pimpinan" }
  },
  "message": "Pengajuan penonaktifan berhasil dibuat dan menunggu persetujuan pengawas."
}
```

Error:

| Kode | Kondisi |
|---|---|
| `403` | Bukan pimpinan. |
| `422` | `reason` kosong; target tidak aktif; target pengawas; target tidak punya laporan terbuka. |
| `409` | Sudah ada pengajuan `menunggu` untuk akun tersebut. |

### 7.3 Daftar pengajuan

`GET /admin/deactivation-requests?status=menunggu&per_page=20`

- `pengawas`: melihat semua pengajuan.
- `pimpinan`: hanya melihat pengajuan yang ia buat.
- Peran lain: `403`.

`status` opsional: `menunggu`, `disetujui`, `ditolak`.

```json
{
  "data": [
    {
      "id": 1,
      "user_id": 4,
      "requested_by": 2,
      "decided_by": null,
      "reason": "Santri melanggar aturan.",
      "status": "menunggu",
      "decided_at": null,
      "created_at": "2026-10-26T05:10:00.000000Z",
      "updated_at": "2026-10-26T05:10:00.000000Z",
      "user": { "id": 4, "name": "Ahmad Fauzi", "email": "ahmad@santri.test", "role": "santri", "is_active": true },
      "requester": { "id": 2, "name": "Pimpinan Pondok", "email": "pimpinan@santri.test", "role": "pimpinan" },
      "decider": null
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1,
    "from": 1,
    "to": 1
  },
  "message": "Daftar pengajuan penonaktifan berhasil diambil"
}
```

### 7.4 Memutuskan pengajuan

`PATCH /admin/deactivation-requests/{id}` — hanya `pengawas`.

```json
{
  "status": "disetujui",
  "catatan": "Disetujui setelah pemeriksaan."
}
```

`status` wajib: `disetujui` atau `ditolak`. `catatan` opsional.

Jika disetujui, dalam satu transaksi backend akan:

1. Mengunci pengajuan dan memastikan statusnya masih `menunggu`.
2. Memastikan pemutus bukan pemohon.
3. Menonaktifkan akun target.
4. Menghapus seluruh token target.
5. Menulis audit `deactivation.approved` dan `account.deactivated`.

Respons sukses:

```json
{
  "data": {
    "id": 1,
    "user_id": 4,
    "requested_by": 2,
    "decided_by": 3,
    "reason": "Santri melanggar aturan.",
    "status": "disetujui",
    "decided_at": "2026-10-26T05:20:00.000000Z",
    "created_at": "2026-10-26T05:10:00.000000Z",
    "updated_at": "2026-10-26T05:20:00.000000Z",
    "user": { "id": 4, "name": "Ahmad Fauzi", "email": "ahmad@santri.test", "role": "santri", "is_active": false },
    "requester": { "id": 2, "name": "Pimpinan Pondok", "email": "pimpinan@santri.test", "role": "pimpinan" },
    "decider": { "id": 3, "name": "Pengawas Yayasan", "email": "pengawas@santri.test", "role": "pengawas" }
  },
  "message": "Pengajuan disetujui dan akun berhasil dinonaktifkan.",
  "target_is_active": false
}
```

Error:

| Kode | Kondisi |
|---|---|
| `403` | Bukan pengawas. |
| `409` | Pengajuan sudah pernah diputuskan. |
| `422` | Status tidak valid; target sudah nonaktif; target pengawas; pimpinan aktif terakhir. |

## 8. Audit log

`GET /admin/audit-logs` — hanya `pengawas`. Read-only dan append-only.

Query opsional:

| Parameter | Contoh | Catatan |
|---|---|---|
| `action` | `report.viewed` | Filter aksi. |
| `actor_id` | `3` | Filter pelaku. |
| `subject_type` | `App\\Models\\Laporan` | Filter tipe objek. |
| `subject_id` | `12` | Filter id objek. |
| `from` | `2026-10-01` | Awal hari. |
| `to` | `2026-10-31` | Akhir hari. |
| `per_page` | `50` | 1–100, default 20. |

```json
{
  "data": [
    {
      "id": 25,
      "actor_id": 3,
      "action": "report.status_changed",
      "subject_type": "App\\Models\\Laporan",
      "subject_id": 12,
      "metadata": { "from": "baru", "to": "diproses" },
      "ip_address": "127.0.0.1",
      "created_at": "2026-10-26T05:30:00.000000Z",
      "actor": {
        "id": 3,
        "name": "Pengawas Yayasan",
        "email": "pengawas@santri.test",
        "role": "pengawas"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1,
    "from": 1,
    "to": 1
  },
  "message": "Log audit berhasil diambil"
}
```

`actor` bisa `null` jika akun pelaku sudah dihapus. `metadata` bisa `null`.

Aksi yang tercatat:

| Aksi | Kapan |
|---|---|
| `report.viewed` | Staff/pengawas membuka detail laporan. |
| `report.deleted` | Santri menghapus laporan miliknya yang masih berstatus `baru`. |
| `report.status_changed` | Status laporan berubah. |
| `report.message_sent` | Staff/pengawas mengirim pesan. |
| `report.note_added` | Catatan internal ditambahkan. |
| `report.escalated` | Laporan dieskalasi. |
| `identity.requested` | Permintaan identitas diajukan. |
| `identity.responded` | Santri menyetujui/menolak permintaan identitas. |
| `identity.toggled` | Santri mengubah tampilan identitas. |
| `account.activated` | Akun diaktifkan. |
| `account.deactivated` | Akun dinonaktifkan. |
| `deactivation.requested` | Pengajuan penonaktifan dibuat. |
| `deactivation.approved` | Pengajuan disetujui. |
| `deactivation.rejected` | Pengajuan ditolak. |

Tidak ada endpoint `PUT`, `PATCH`, atau `DELETE` untuk audit log.

## 9. Endpoint laporan yang ada (ringkas)

| Method | Path | Akses | Catatan |
|---|---|---|---|
| `GET` | `/reports` | staff + pengawas | Daftar mengikuti visibilitas peran. |
| `GET` | `/reports/{id}` | staff + pengawas + pemilik | Menandai dibaca dan menulis audit untuk staff/pengawas. |
| `DELETE` | `/reports/{id}` | pemilik (santri) | Hanya saat status `baru`; menghapus laporan beserta pesan, catatan, permintaan identitas, dan bukti terkait secara permanen. |
| `PATCH` | `/reports/{id}/status` | staff + pengawas | Body `{ "status": "diproses" \| "selesai" }`. |
| `POST` | `/petugas/laporan/{id}/eskalasi` | pengurus | Body `tujuan` (`polsek`, `uppt_ppa`, `pengurus_pusat`), `nomor_wa_tujuan` (format internasional), dan `catatan_pengurus`. Mengubah status menjadi `DITERUSKAN_KE_BERWAJIB`, mengunci laporan, mencatat audit, serta mengembalikan `whatsapp_url` dan `pdf_download_url`. |
| `GET` | `/petugas/laporan/{id}/eskalasi/pdf` | pengurus | Mengunduh PDF surat pengantar untuk laporan yang sudah dieskalasi. |
| `POST` | `/reports/{id}/escalate` | pengurus | Alias path lama; sekarang memakai payload dan perilaku endpoint eskalasi baru. |
| `GET` | `/reports/{id}/messages` | staff + pengawas + pemilik | Menandai dibaca. |
| `POST` | `/reports/{id}/messages` | staff + pengawas + pemilik | Body `{ "isi": "..." }`. |
| `POST` | `/reports/{id}/notes` | staff + pengawas | Catatan internal. |
| `POST` | `/reports/{id}/identity-request` | pimpinan + pengawas | Hanya untuk laporan yang boleh diakses; body `alasan` wajib, `bukti` opsional (jpg/jpeg/png/pdf, maks 10 MB). |
| `PATCH` | `/reports/{id}/identity-request` | pemilik (santri) | Body `{ "status": "disetujui" \| "ditolak" \| 1 \| 0 }`. |
| `PATCH` | `/reports/{id}/identity` | pemilik (santri) | Toggle atau set `tampilkan_identitas`. |

Setelah eskalasi berhasil, laporan berstatus `DITERUSKAN_KE_BERWAJIB` dan terkunci. Endpoint mutasi laporan menolak perubahan maupun penghapusan pada laporan terkunci.
