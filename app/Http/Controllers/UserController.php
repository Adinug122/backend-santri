<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4'],
        ]);

        $identifier = $request->string('identifier')->trim()->toString();
        $userQuery = User::query();

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $userQuery->where('email', $identifier);
        } else {
            $userQuery->whereHas('santri', fn ($query) => $query->where('nis', $identifier));
        }

        $user = $userQuery->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Login gagal, identifier atau password salah',
            ], 401);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun dinonaktifkan',
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
            'role' => $user->role,
            'dashboard' => '/dashboard/'.$user->role,
            'must_change_password' => (bool) $user->must_change_password,
            'message' => 'Login berhasil',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    private function userPayload(User $user): array
    {
        $user->loadMissing('santri');

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'must_change_password' => (bool) $user->must_change_password,
            'santri' => $user->role === 'santri' && $user->santri !== null
                ? [
                    'nis' => $user->santri->nis,
                    'telepon_ortu' => $user->santri->telepon_ortu,
                ]
                : null,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:4', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Password saat ini tidak sesuai',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
            'must_change_password' => false,
        ]);

        return response()->json([
            'message' => 'Password berhasil diperbarui',
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        if ($request->user()) {
            $request->user()->currentAccessToken()?->delete();
        }

        return response()->json([
            'message' => 'Logout berhasil',
        ]);
    }
}
