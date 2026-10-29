<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Laporan extends Model
{
    public const LATE_AFTER_HOURS = 24;

    public const STATUS_ESCALATED = 'DITERUSKAN_KE_BERWAJIB';

    /**
     * Kolom penanda "sudah dibaca" untuk setiap peran pengguna.
     *
     * @var array<string, string>
     */
    public const READ_COLUMNS = [
        'santri' => 'santri_dibaca',
        'pengurus' => 'pengurus_dibaca',
        'pimpinan' => 'pimpinan_dibaca',
        'pengawas' => 'pengawas_dibaca',
    ];

    public const PENGURUS_RESTRICTED_CATEGORY = 'masalah_pengurus';

    protected $table = 'laporans';

    protected $fillable = [
        'users_id',
        'no_laporan',
        'kategori',
        'isi',
        'tampilkan_identitas',
        'menyangkut_pimpinan',
        'status',
        'status_diubah',
        'is_locked',
        'santri_dibaca',
        'pengurus_dibaca',
        'pimpinan_dibaca',
        'pengawas_dibaca',
        'waktu',
    ];

    protected $casts = [
        'tampilkan_identitas' => 'boolean',
        'menyangkut_pimpinan' => 'boolean',
        'waktu' => 'datetime',
        'status' => 'string',
        'status_diubah' => 'datetime',
        'is_locked' => 'boolean',
        'santri_dibaca' => 'datetime',
        'pengurus_dibaca' => 'datetime',
        'pimpinan_dibaca' => 'datetime',
        'pengawas_dibaca' => 'datetime',
    ];

    /**
     * Kolom penanda dibaca untuk sebuah peran, atau null bila perannya tidak dikenal.
     */
    public static function readColumnFor(?string $role): ?string
    {
        return self::READ_COLUMNS[$role] ?? null;
    }

    /**
     * Laporan masalah pengurus hanya boleh dibaca pengurus melalui endpoint lain.
     */
    public function isRestrictedForPengurus(): bool
    {
        $normalized = strtolower(str_replace([' ', '-'], '_', trim((string) $this->kategori)));

        return $normalized === self::PENGURUS_RESTRICTED_CATEGORY;
    }

    public function isMenyangkutPimpinan(): bool
    {
        return (bool) $this->menyangkut_pimpinan;
    }

    public function scopeVisibleToRole(Builder $query, ?string $role): Builder
    {
        return match ($role) {
            'pengurus' => $query
                ->where('menyangkut_pimpinan', false)
                ->whereRaw(
                    "LOWER(REPLACE(REPLACE(TRIM(kategori), ' ', '_'), '-', '_')) <> ?",
                    [self::PENGURUS_RESTRICTED_CATEGORY],
                ),
            'pimpinan' => $query->where('menyangkut_pimpinan', false),
            'pengawas' => $query->where('menyangkut_pimpinan', true),
            default => $query,
        };
    }

    public function scopeTerlambat(Builder $query): Builder
    {
        return $query
            ->where('status', 'baru')
            ->where('created_at', '<', now()->subHours(self::LATE_AFTER_HOURS));
    }

    public function getTerlambatAttribute(): bool
    {
        return $this->status === 'baru'
            && $this->created_at !== null
            && $this->created_at->lt(now()->subHours(self::LATE_AFTER_HOURS));
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
