<?php

namespace Tests\Feature;

use App\Models\Santri;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
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
            ->assertJsonPath('user.santri.nis', 'S-12345')
            ->assertJsonPath('user.santri.telepon_ortu', '081234567890')
            ->assertJsonPath('user.must_change_password', false)
            ->assertJsonStructure(['access_token', 'token_type', 'user']);
    }

    public function test_login_response_includes_temporary_password_flag_and_santri_profile(): void
    {
        $user = User::create([
            'name' => 'Santri Baru',
            'email' => 'santri.baru.login@example.test',
            'role' => 'santri',
            'password' => 'password123',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        Santri::create([
            'users_id' => $user->id,
            'nis' => '20240001',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'santri.baru.login@example.test',
            'password' => 'password123',
        ])
            ->assertOk()
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.santri.nis', '20240001')
            ->assertJsonPath('user.santri.telepon_ortu', '081234567890');
    }

    public function test_user_endpoint_returns_santri_profile_and_clears_must_change_password_after_change(): void
    {
        $user = User::create([
            'name' => 'Santri Profil',
            'email' => 'santri.profil@example.test',
            'role' => 'santri',
            'password' => 'password123',
            'is_active' => true,
            'must_change_password' => true,
        ]);

        Santri::create([
            'users_id' => $user->id,
            'nis' => '20240002',
            'jenis_kelamin' => 'perempuan',
            'telepon_ortu' => '081298765432',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('santri.nis', '20240002')
            ->assertJsonPath('santri.telepon_ortu', '081298765432')
            ->assertJsonPath('must_change_password', true);

        $this->postJson('/api/auth/password', [
            'current_password' => 'password123',
            'password' => 'PasswordBaru123',
            'password_confirmation' => 'PasswordBaru123',
        ])->assertOk();

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('must_change_password', false);
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
            ->assertJsonPath('user.santri', null)
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
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Akun dinonaktifkan');
    }

    public function test_login_with_wrong_password_does_not_reveal_whether_the_account_is_deactivated(): void
    {
        User::create([
            'name' => 'Pengurus Nonaktif',
            'email' => 'nonaktif-password@example.test',
            'role' => 'pengurus',
            'password' => 'password123',
            'is_active' => false,
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'nonaktif-password@example.test',
            'password' => 'wrong-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Login gagal, identifier atau password salah');
    }

    public function test_user_can_login_with_four_character_password(): void
    {
        User::create([
            'name' => 'Santri Test',
            'email' => 'password.minimum@example.test',
            'role' => 'santri',
            'password' => '1234',
            'is_active' => true,
        ]);

        $this->postJson('/api/auth/login', [
            'identifier' => 'password.minimum@example.test',
            'password' => '1234',
        ])->assertOk();
    }

    public function test_user_can_change_password_to_four_characters(): void
    {
        $user = User::create([
            'name' => 'Santri Test',
            'email' => 'password.change@example.test',
            'role' => 'santri',
            'password' => 'password123',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/password', [
            'current_password' => 'password123',
            'password' => '1234',
            'password_confirmation' => '1234',
        ])->assertOk();

        $this->assertTrue(Hash::check('1234', $user->fresh()->password));
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_santri_created_through_the_api_must_change_password_before_using_protected_apis(): void
    {
        $response = $this->postJson('/api/santri', [
            'name' => 'Santri Baru',
            'email' => 'santri.baru@example.test',
            'nis' => '20260050',
            'jenis_kelamin' => 'laki',
            'telepon_ortu' => '081234567890',
        ])->assertOk();

        $password = $response->json('password');
        $this->assertIsString($password);
        $this->assertGreaterThanOrEqual(4, strlen($password));

        $login = $this->postJson('/api/auth/login', [
            'identifier' => '20260050',
            'password' => $password,
        ])
            ->assertOk()
            ->assertJsonPath('role', 'santri')
            ->assertJsonPath('must_change_password', true)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.santri.nis', '20260050');

        $token = $login->json('access_token');

        $this->withToken($token)
            ->getJson('/api/reports/mine')
            ->assertForbidden()
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        $this->withToken($token)
            ->postJson('/api/auth/password', [
                'current_password' => $password,
                'password' => 'PasswordBaru123',
                'password_confirmation' => 'PasswordBaru123',
            ])->assertOk();

        $this->withToken($token)
            ->getJson('/api/reports/mine')
            ->assertOk();
    }

    public function test_user_cannot_change_password_to_fewer_than_four_characters(): void
    {
        $user = User::create([
            'name' => 'Santri Test',
            'email' => 'password.short@example.test',
            'role' => 'santri',
            'password' => 'password123',
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/auth/password', [
            'current_password' => 'password123',
            'password' => '123',
            'password_confirmation' => '123',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check('password123', $user->fresh()->password));
    }
}
