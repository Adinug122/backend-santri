<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Laporan extends Model
{
    protected $table = 'laporans';

    protected $fillable = [
        'users_id',
        'no_laporan',
        'kategori',
        'isi',
        'tampilkan_identitas',
        'status',
        'waktu',
    ];

    protected $casts = [
        'tampilkan_identitas' => 'boolean',
        'waktu' => 'datetime',
        'status' => 'string',
    ];

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
