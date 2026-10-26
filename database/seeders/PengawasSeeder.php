<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PengawasSeeder extends Seeder
{
    /**
     * Seed the independent supervisory account.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'pengawas@santri.test'],
            [
                'name' => 'Pengawas Yayasan',
                'password' => Hash::make('password'),
                'role' => 'pengawas',
                'is_active' => true,
                'must_change_password' => false,
            ]
        );
    }
}
