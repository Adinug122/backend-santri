<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Laporan extends Model
{
    /**
     * Kolom penanda "sudah dibaca" untuk setiap peran pengguna.
     *
     * @var array<string, string>
     */
    public const READ_COLUMNS = [
        'santri' => 'santri_dibaca',
        'pengurus' => 'pengurus_dibaca',
        'pimpinan' => 'pimpinan_dibaca',
    ];

    protected $table = 'laporans';

    protected $fillable = [
        'users_id',
        'no_laporan',
        'kategori',
        'isi',
        'tampilkan_identitas',
        'status',
        'status_diubah',
        'santri_dibaca',
        'pengurus_dibaca',
        'pimpinan_dibaca',
        'waktu',
    ];

    protected $casts = [
        'tampilkan_identitas' => 'boolean',
        'waktu' => 'datetime',
        'status' => 'string',
        'status_diubah' => 'datetime',
        'santri_dibaca' => 'datetime',
        'pengurus_dibaca' => 'datetime',
        'pimpinan_dibaca' => 'datetime',
    ];

    /**
     * Kolom penanda dibaca untuk sebuah peran, atau null bila perannya tidak dikenal.
     */
    public static function readColumnFor(?string $role): ?string
    {
        return self::READ_COLUMNS[$role] ?? null;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }

    public function catatans()
    {
        return $this->hasMany(Catatan::class, 'laporan_id');
    }

    public function identityRequests()
    {
        return $this->hasMany(IdentityRequest::class, 'laporan_id');
    }

    public function latestIdentityRequest(): HasOne
    {
        return $this->hasOne(IdentityRequest::class, 'laporan_id')->latestOfMany();
    }

    public function percakapans()
    {
        return $this->hasMany(Percakapan::class, 'laporan_id');
    }
}
