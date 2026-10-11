<?php

namespace App\Http\Controllers;

use App\Models\Santri;
use App\Models\User;
use Illuminate\Http\Request;

class SantriController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $user = User::where('role','santri')->get();

        return response()->json([
            'santri' => $user,
            'message' => 'Data santri berhasil diambil'
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email|unique:users,email',
            'nis' => 'required|string|unique:santris,nis',
            'jenis_kelamin' => 'required|in:laki,perempuan',
            'telepon_ortu' => 'required|string|min:10|max:20'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'role' => 'santri',
            'is_active' => true,
            'must_change_password' => true,
            'password' => bcrypt('password')
        ]);

        $santri = Santri::create([
            'users_id' => $user->id,
            'nis' => $request->nis,
            'jenis_kelamin' => $request->jenis_kelamin,
            'telepon_ortu' => $request->telepon_ortu
        ]);

        return response()->json([
            'santri' => $santri->load('user'),
            'message'=> 'Santri Berhasil ditambahkan'
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Santri $santri)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Santri $santri)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Santri $santri)
    {
         $request->validate([
            'name' => 'sometimes|string',
            'email' => ['sometimes','email','unique:users,email,'.$santri->users_id],
            'nis' => ['sometimes','string','unique:santris,nis,'.$santri->id],
            'jenis_kelamin' => 'sometimes|in:laki,perempuan',
            'telepon_ortu' => 'sometimes|string|min:10|max:20'
        ]);

        $user = $santri->user;
        
        if ($request->has('name') || $request->has('email')) {
            $user->update([
                'name' => $request->input('name', $user->name),
                'email' => $request->input('email', $user->email),
            ]);
        }

        $santri->update([
            'nis' => $request->input('nis', $santri->nis),
            'jenis_kelamin' => $request->input('jenis_kelamin', $santri->jenis_kelamin),
            'telepon_ortu' => $request->input('telepon_ortu', $santri->telepon_ortu)
        ]);
        
        return response()->json([
            'santri' => $santri->fresh()->load('user'),
            'message'=> 'Santri Berhasil diubah'
        ]);
        
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Santri $santri)
    {
        $user = $santri->user;
        $santri->delete();

        if ($user) {
            $user->delete();
        }

        return response()->json([
            'message'=> 'Santri Berhasil dihapus'
        ]);
    }

    public function toggleSantri(Santri $santri)
    {
        $user = $santri->user;
        $user->is_active = ! $user->is_active;
        $user->save();

        return response()->json([
            'message' => $user->is_active ? 'Santri Berhasil diaktifkan' : 'Santri Berhasil dinonaktifkan',
            'is_active' => $user->is_active,
        ]);
    }
}
