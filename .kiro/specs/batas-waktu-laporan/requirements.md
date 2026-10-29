# Requirements Document

## Introduction

Fitur **Batas Waktu Laporan** memberikan tekanan transparan agar laporan santri tidak didiamkan
terlalu lama. Laporan dengan status `baru` yang telah melewati 24 jam sejak dibuat diklasifikasikan
sebagai *terlambat*. Klasifikasi ini dihitung secara dinamis dari `created_at` — tidak ada kolom
tambahan di database. Pengurus dan pimpinan melihat penanda visual "Lewat batas" di daftar laporan;
pimpinan melihat angka ringkasan di dashboard; pengawas mendapat notifikasi laporan terlambat; dan
santri membaca janji waktu tinjauan yang jujur tanpa menyebutkan eskalasi.

Backend Laravel sudah memiliki pondasi: `Laporan::LATE_AFTER_HOURS = 24`,
`getTerlambatAttribute()`, `scopeTerlambat()`, dan field `jumlah_terlambat` di endpoint dashboard.
Field `terlambat` juga sudah dimasukkan di `reportPayload()`. Fitur ini mengkonsolidasi,
melengkapi, dan mendokumentasikan kontrak perilaku untuk semua lapisan — backend, notifikasi,
dan frontend.

## Glossary

- **System**: Sistem Pelaporan Santri (aplikasi secara keseluruhan).
- **API**: Backend Laravel yang menyajikan endpoint JSON.
- **Laporan_Terlambat**: Laporan dengan `status = 'baru'` dan `created_at ≤ now() − 24 jam`.
- **Laporan_Baru**: Laporan dengan `status = 'baru'` dan `created_at > now() − 24 jam`.
- **Terlambat_Flag**: Field boolean `terlambat` dalam respons JSON laporan.
- **Jumlah_Terlambat**: Field integer `jumlah_terlambat` dalam respons JSON ringkasan dashboard.
- **Dashboard_Summary**: Endpoint `GET /api/dashboard/summary`.
- **Report_List**: Endpoint `GET /api/reports` (daftar laporan untuk staf).
- **Report_Detail**: Endpoint `GET /api/reports/{id}` (detail satu laporan).
- **My_Reports**: Endpoint `GET /api/reports/mine` (daftar laporan milik santri).
- **Notification_System**: Subsistem notifikasi yang melacak pembaruan laporan per peran.
- **Pengurus**: Staf pengurus yang menangani laporan kategori umum.
- **Pimpinan**: Pimpinan pesantren yang mengawasi semua laporan (kecuali `menyangkut_pimpinan`).
- **Pengawas**: Supervisor yang hanya menangani laporan bertanda `menyangkut_pimpinan`.
- **Santri**: Siswa yang membuat dan memiliki laporan.
- **Badge_Lewat_Batas**: Elemen UI berwarna merah dengan teks "Lewat batas" yang ditampilkan frontend.
- **Teks_Janji_24_Jam**: Kalimat "Laporanmu akan ditinjau dalam 24 jam." yang ditampilkan ke santri.
- **LATE_AFTER_HOURS**: Konstanta pada model `Laporan` bernilai `24` (satuan: jam).

---

## Requirements

### Requirement 1: Klasifikasi Laporan Terlambat

**User Story:** Sebagai sistem, saya ingin mengklasifikasikan laporan sebagai terlambat berdasarkan
waktu sejak dibuat, agar setiap lapisan aplikasi menggunakan definisi yang konsisten.

#### Acceptance Criteria

1. THE System SHALL mengklasifikasikan sebuah laporan sebagai Laporan_Terlambat apabila `status =
   'baru'` dan `created_at ≤ now() − LATE_AFTER_HOURS jam`.
2. THE System SHALL mengklasifikasikan sebuah laporan sebagai bukan Laporan_Terlambat apabila
   `status` bukan `'baru'`, tanpa memperhatikan nilai `created_at`.
3. THE System SHALL mengklasifikasikan sebuah laporan sebagai bukan Laporan_Terlambat apabila
   `created_at > now() − LATE_AFTER_HOURS jam`, tanpa memperhatikan nilai `status`.
4. THE System SHALL menghitung klasifikasi Laporan_Terlambat secara dinamis dari `created_at` tanpa
   memerlukan kolom tambahan di tabel `laporans`.
5. THE System SHALL menggunakan konstanta `LATE_AFTER_HOURS = 24` sebagai satu-satunya sumber
   kebenaran untuk ambang batas keterlambatan di seluruh backend.

---

### Requirement 2: Field `terlambat` di Respons API Laporan

**User Story:** Sebagai pengembang frontend, saya ingin setiap objek laporan dalam respons API
menyertakan field `terlambat`, agar frontend tidak perlu menghitung keterlambatan sendiri.

#### Acceptance Criteria

1. WHEN endpoint Report_List, Report_Detail, atau My_Reports mengembalikan objek laporan, THE API
   SHALL menyertakan field boolean `terlambat` pada setiap objek laporan dalam respons.
2. WHEN sebuah laporan merupakan Laporan_Terlambat, THE API SHALL mengembalikan `"terlambat": true`
   pada objek laporan tersebut.
3. WHEN sebuah laporan bukan Laporan_Terlambat, THE API SHALL mengembalikan `"terlambat": false`
   pada objek laporan tersebut.
4. IF sebuah laporan memiliki `menyangkut_pimpinan = true` dan peran peminta adalah `pimpinan` atau
   `pengurus`, THEN THE API SHALL mengecualikan field `terlambat` dari respons laporan tersebut
   sesuai aturan visibilitas yang sudah ada.
5. THE API SHALL menghitung nilai `terlambat` di sisi server dan tidak memerlukan parameter query
   dari klien.

---

### Requirement 3: `jumlah_terlambat` di Endpoint Dashboard Summary

**User Story:** Sebagai pimpinan, saya ingin melihat jumlah laporan yang terlambat di ringkasan
dashboard, agar saya bisa memantau responsivitas penanganan laporan secara sekilas.

#### Acceptance Criteria

1. WHEN endpoint Dashboard_Summary dipanggil oleh pengguna dengan peran `pimpinan`, THE API SHALL
   menyertakan field integer `jumlah_terlambat` dalam objek `data` respons.
2. WHEN endpoint Dashboard_Summary dipanggil oleh pengguna dengan peran `pengawas`, THE API SHALL
   menyertakan field integer `jumlah_terlambat` dalam objek `data` respons, dihitung hanya dari
   laporan yang memiliki `menyangkut_pimpinan = true`.
3. THE API SHALL menghitung `jumlah_terlambat` menggunakan scope `terlambat` yang dibatasi oleh
   scope `visibleToRole` sesuai peran peminta.
4. WHEN tidak ada Laporan_Terlambat yang terlihat oleh peran peminta, THE API SHALL mengembalikan
   `"jumlah_terlambat": 0`.
5. IF pengguna dengan peran selain `pimpinan` dan `pengawas` memanggil endpoint Dashboard_Summary,
   THEN THE API SHALL mengembalikan respons `403 Forbidden`.

---

### Requirement 4: Urutan Prioritas Laporan di Daftar Staf

**User Story:** Sebagai pengurus atau pimpinan, saya ingin laporan yang terlambat muncul di bagian
atas daftar, agar laporan yang mendesak langsung terlihat tanpa perlu menggulir.

#### Acceptance Criteria

1. WHEN endpoint Report_List mengembalikan daftar laporan kepada pengguna dengan peran `pengurus`,
   `pimpinan`, atau `pengawas`, THE API SHALL mengurutkan Laporan_Terlambat sebelum laporan lain.
2. WHILE beberapa laporan sama-sama berstatus Laporan_Terlambat, THE API SHALL mengurutkan
   laporan-laporan tersebut dari yang terbaru (`created_at` terbesar) ke yang terlama.
3. WHILE beberapa laporan sama-sama bukan Laporan_Terlambat, THE API SHALL mengurutkan
   laporan-laporan tersebut dari yang terbaru ke yang terlama.
4. THE API SHALL menerapkan pengurutan ini setelah scope `visibleToRole`, sehingga laporan yang
   tidak boleh dilihat peran peminta tetap tidak muncul.

---

### Requirement 5: Notifikasi Pengawas untuk Laporan Terlambat

**User Story:** Sebagai pengawas, saya ingin menerima notifikasi ketika ada laporan terlambat yang
belum ditangani, agar saya bisa melakukan tindak lanjut pengawasan.

#### Acceptance Criteria

1. WHEN sebuah laporan bertanda `menyangkut_pimpinan = true` menjadi Laporan_Terlambat (melewati
   24 jam sejak `created_at` dengan `status = 'baru'`), THE Notification_System SHALL membuat
   entri notifikasi bertipe `laporan_terlambat` untuk peran `pengawas`.
2. THE Notification_System SHALL hanya membuat notifikasi `laporan_terlambat` untuk laporan yang
   terlihat oleh peran `pengawas` (yaitu `menyangkut_pimpinan = true`).
3. IF notifikasi `laporan_terlambat` untuk sebuah laporan sudah ada dan laporan tersebut masih
   berstatus `baru`, THEN THE Notification_System SHALL tidak membuat entri duplikat.
4. WHEN status laporan berubah dari `baru` menjadi `diproses` atau `selesai`, THE
   Notification_System SHALL menandai notifikasi `laporan_terlambat` terkait sebagai sudah
   tidak aktif.

---

### Requirement 6: Badge "Lewat Batas" di Daftar Laporan Frontend

**User Story:** Sebagai pengurus atau pimpinan, saya ingin melihat badge "Lewat batas" pada laporan
yang terlambat di daftar laporan, agar saya langsung mengetahui laporan mana yang membutuhkan
perhatian segera.

#### Acceptance Criteria

1. WHEN frontend menampilkan daftar laporan kepada pengguna dengan peran `pengurus` atau `pimpinan`,
   THE System SHALL menampilkan Badge_Lewat_Batas berwarna merah pada setiap item laporan yang
   memiliki `terlambat = true` dari respons API.
2. WHEN frontend menampilkan daftar laporan kepada pengguna dengan peran `pengawas`, THE System
   SHALL menampilkan Badge_Lewat_Batas berwarna merah pada setiap item laporan yang memiliki
   `terlambat = true` dari respons API.
3. WHEN sebuah item laporan memiliki `terlambat = false`, THE System SHALL tidak menampilkan
   Badge_Lewat_Batas pada item tersebut.
4. THE System SHALL menentukan status keterlambatan dari field `terlambat` dalam respons API dan
   tidak menghitung ulang keterlambatan di sisi frontend.
5. THE System SHALL menampilkan teks Badge_Lewat_Batas sebagai "Lewat batas".

---

### Requirement 7: Kartu Angka Laporan Terlambat di Dashboard Pimpinan

**User Story:** Sebagai pimpinan, saya ingin melihat kartu angka yang menampilkan jumlah laporan
terlambat di halaman dashboard, agar saya bisa memantau kondisi penanganan laporan secara cepat.

#### Acceptance Criteria

1. WHEN halaman dashboard pimpinan dimuat, THE System SHALL menampilkan kartu angka yang
   menunjukkan nilai `jumlah_terlambat` dari respons endpoint Dashboard_Summary.
2. WHEN `jumlah_terlambat = 0`, THE System SHALL tetap menampilkan kartu angka dengan nilai nol,
   menandakan tidak ada laporan yang terlambat.
3. WHEN `jumlah_terlambat > 0`, THE System SHALL menampilkan kartu angka dengan warna atau tanda
   yang membedakannya dari kartu statistik biasa untuk menarik perhatian pimpinan.
4. THE System SHALL mengambil nilai `jumlah_terlambat` dari respons API dan tidak menghitung
   jumlah laporan terlambat di sisi frontend.

---

### Requirement 8: Teks Janji Waktu Tinjauan untuk Santri

**User Story:** Sebagai santri, saya ingin membaca janji waktu tinjauan yang jujur dan tidak
membingungkan, agar saya tahu kapan laporan saya akan direspons tanpa harus bertanya.

#### Acceptance Criteria

1. THE System SHALL menampilkan Teks_Janji_24_Jam "Laporanmu akan ditinjau dalam 24 jam." pada
   halaman beranda santri.
2. THE System SHALL menampilkan Teks_Janji_24_Jam "Laporanmu akan ditinjau dalam 24 jam." pada
   formulir Buat Laporan yang diakses santri.
3. THE System SHALL menampilkan Teks_Janji_24_Jam "Laporanmu akan ditinjau dalam 24 jam." pada
   halaman Login.
4. THE System SHALL tidak menggunakan frasa yang merujuk pada eskalasi otomatis ke pimpinan di
   ketiga lokasi tersebut.
5. IF teks yang ada saat ini masih menyebutkan "naik ke pimpinan" atau frasa serupa di ketiga
   lokasi tersebut, THEN THE System SHALL mengganti teks tersebut dengan Teks_Janji_24_Jam.
