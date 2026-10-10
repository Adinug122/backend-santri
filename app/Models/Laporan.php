<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Laporan extends Model
{
    protected $fillable = [
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

    public function catatans()
    {
        return $this->hasMany(Catatan::class, 'laporan_id');
    }

    public function percakapans()
    {
        return $this->hasMany(Percakapan::class, 'laporan_id');
    }
}
