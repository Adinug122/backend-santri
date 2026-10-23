<?php

namespace Tests\Feature;

use App\Models\Santri;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SantriApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_santri_list_includes_nis_and_parent_phone(): void
    {
        $user = $this->createSantri('20240001', '081234567890');

        $this->getJson('/api/santri')
            ->assertOk()
            ->assertJsonPath('santri.0.nis', '20240001')
            ->assertJsonPath('santri.0.telepon_ortu', '081234567890')
            ->assertJsonPath('santri.0.santri.nis', '20240001')
            ->assertJsonPath('santri.0.email', $user->email);
    }

    public function test_santri_detail_returns_the_santri_and_user(): void
    {
        $user = $this->createSantri('20240002', '081298765432');

        $this->getJson('/api/santri/'.$user->santri->id)
            ->assertOk()
            ->assertJsonPath('santri.nis', '20240002')
            ->assertJsonPath('santri.telepon_ortu', '081298765432')
            ->assertJsonPath('santri.user.email', $user->email);
    }

    private function createSantri(string $nis, string $teleponOrtu): User
    {
        $user = User::create([
            'name' => 'Santri '.$nis,
            'email' => $nis.'@santri.test',
            'role' => 'santri',
            'password' => 'password123',
            'is_active' => true,
        ]);

        Santri::create([
            'users_id' => $user->id,
            'nis' => $nis,
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => $teleponOrtu,
        ]);

        return $user->load('santri');
    }
}
