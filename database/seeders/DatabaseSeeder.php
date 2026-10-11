<?php

namespace Database\Seeders;

use App\Models\Catatan;
use App\Models\Laporan;
use App\Models\Percakapan;
use App\Models\Santri;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // --- Users ---
        $pimpinan = User::create([
            'name'      => 'Pimpinan Pondok',
            'email'     => 'pimpinan@santri.test',
            'password'  => Hash::make('password'),
            'role'      => 'pimpinan',
            'is_active' => true,
        ]);

        $pengurus = User::create([
            'name'      => 'Pengurus Pondok',
            'email'     => 'pengurus@santri.test',
            'password'  => Hash::make('password'),
            'role'      => 'pengurus',
            'is_active' => true,
        ]);

        $santriUser1 = User::create([
            'name'      => 'Ahmad Fauzi',
            'email'     => 'ahmad@santri.test',
            'password'  => Hash::make('password'),
            'role'      => 'santri',
            'is_active' => true,
        ]);

        $santriUser2 = User::create([
            'name'      => 'Siti Aisyah',
            'email'     => 'siti@santri.test',
            'password'  => Hash::make('password'),
            'role'      => 'santri',
            'is_active' => true,
        ]);

        // --- Santris ---
        Santri::create([
            'users_id'      => $santriUser1->id,
            'nis'           => '2024001',
            'jenis_kelamin' => 'laki',
            'telepon_ortu'  => '081234567890',
        ]);

        Santri::create([
            'users_id'      => $santriUser2->id,
            'nis'           => '2024002',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu'  => '089876543210',
        ]);

        // --- Laporans ---
        $laporan1 = Laporan::create([
            'no_laporan'          => 'LAP-001',
            'kategori'            => 'Keamanan',
            'isi'                 => 'Ada santri yang keluar asrama tanpa izin pada malam hari.',
            'tampilkan_identitas' => false,
            'status'              => 'baru',
            'waktu'               => now()->subDays(3),
        ]);

        $laporan2 = Laporan::create([
            'no_laporan'          => 'LAP-002',
            'kategori'            => 'Fasilitas',
            'isi'                 => 'Toilet kamar mandi blok B rusak dan tidak bisa digunakan.',
            'tampilkan_identitas' => true,
            'status'              => 'diproses',
            'waktu'               => now()->subDays(1),
        ]);

        $laporan3 = Laporan::create([
            'no_laporan'          => 'LAP-003',
            'kategori'            => 'Kesehatan',
            'isi'                 => 'Beberapa santri mengeluhkan makanan yang kurang bergizi.',
            'tampilkan_identitas' => false,
            'status'              => 'selesai',
            'waktu'               => now()->subDays(7),
        ]);

        // --- Catatans ---
        Catatan::create([
            'catatan'    => 'Laporan sudah diteruskan ke bagian keamanan untuk ditindaklanjuti.',
            'users_id'   => $pengurus->id,
            'laporan_id' => $laporan1->id,
        ]);

        Catatan::create([
            'catatan'    => 'Teknisi sudah dipanggil untuk memperbaiki fasilitas.',
            'users_id'   => $pengurus->id,
            'laporan_id' => $laporan2->id,
        ]);

        Catatan::create([
            'catatan'    => 'Sudah dikomunikasikan dengan bagian dapur dan diselesaikan.',
            'users_id'   => $pimpinan->id,
            'laporan_id' => $laporan3->id,
        ]);

        // --- Percakapans ---
        Percakapan::create([
            'isi'         => 'Terima kasih sudah melapor, kami akan segera menindaklanjuti.',
            'pengirim_id' => $pengurus->id,
            'laporan_id'  => $laporan1->id,
        ]);

        Percakapan::create([
            'isi'         => 'Apakah ada informasi tambahan mengenai waktu kejadian?',
            'pengirim_id' => $pengurus->id,
            'laporan_id'  => $laporan1->id,
        ]);

        Percakapan::create([
            'isi'         => 'Perbaikan dijadwalkan besok pagi.',
            'pengirim_id' => $pengurus->id,
            'laporan_id'  => $laporan2->id,
        ]);
    }
}
