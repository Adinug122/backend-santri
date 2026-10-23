<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityRequest extends Model
{
    protected $fillable = [
        'laporan_id',
        'users_id',
        'alasan',
        'bukti_path',
        'status',
        'dijawab',
    ];

    protected $casts = [
        'dijawab' => 'datetime',
    ];

    public function laporan(): BelongsTo
    {
        return $this->belongsTo(Laporan::class, 'laporan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}
