<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Santri extends Model
{
    protected $fillable = [
        'users_id',
        'jenis_kelamin',
        'telepon_ortu',
    ];

    protected $casts = [
        'jenis_kelamin' => 'string',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'users_id');
    }
}
