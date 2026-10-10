<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserController extends Controller
{
    public function login(Request $request){
        $request->validate([
        'email'    => 'required|email',
        'password' => 'required|min:8',
        ]);

        if(Auth::attempt([
              'email'    => $request->email,
        'password' => $request->password,
        ])){
            
            $user = Auth::user();
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type' => 'Bearer',
                'user' => $user,
                'message' => 'Login berhasil'
            ]);
        }else{
            
            return response()->json([
                'message' => 'Login gagal, email atau password salah'
            ], 401);
        }
    }
}
