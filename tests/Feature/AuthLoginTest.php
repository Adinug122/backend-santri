<?php

namespace Tests\Feature;

use App\Models\Santri;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_santri_can_login_with_nis_and_get_santri_dashboard(): void
    {
        $user = User::create([
            'name' => 'Santri Test',
            'email' => 'santri@example.test',
            'role' => 'santri',
            'password' => 'password123',
            'is_active' => true,
        ]);

        Santri::create([
            'users_id' => $user->id,
            'nis' => 'S-12345',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'S-12345',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'santri')
            ->assertJsonPath('dashboard', '/dashboard/santri')
            ->assertJsonStructure(['access_token', 'token_type', 'user']);
    }

    public function test_staff_can_login_with_email_and_get_dashboard_for_their_role(): void
    {
        User::create([
            'name' => 'Pengurus Test',
            'email' => 'pengurus@example.test',
            'role' => 'pengurus',
            'password' => 'password123',
            'is_active' => true,
        ]);

        User::create([
            'name' => 'Pimpinan Test',
            'email' => 'pimpinan@example.test',
            'role' => 'pimpinan',
            'password' => 'password123',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'pengurus@example.test',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'pengurus')
            ->assertJsonPath('dashboard', '/dashboard/pengurus');

        $this->postJson('/api/auth/login', [
            'identifier' => 'pimpinan@example.test',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('role', 'pimpinan')
            ->assertJsonPath('dashboard', '/dashboard/pimpinan');
    }

    public function test_inactive_user_cannot_login(): void
    {
        User::create([
            'name' => 'Pengurus Nonaktif',
            'email' => 'nonaktif@example.test',
            'role' => 'pengurus',
            'password' => 'password123',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'nonaktif@example.test',
            'password' => 'password123',
        ])->assertUnauthorized();
    }
}
