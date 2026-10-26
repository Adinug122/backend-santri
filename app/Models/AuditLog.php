<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const REPORT_VIEWED = 'report.viewed';

    public const REPORT_STATUS_CHANGED = 'report.status_changed';

    public const REPORT_MESSAGE_SENT = 'report.message_sent';

    public const REPORT_NOTE_ADDED = 'report.note_added';

    public const REPORT_ESCALATED = 'report.escalated';

    public const IDENTITY_REQUESTED = 'identity.requested';

    public const IDENTITY_RESPONDED = 'identity.responded';

    public const IDENTITY_TOGGLED = 'identity.toggled';

    public const ACCOUNT_ACTIVATED = 'account.activated';

    public const ACCOUNT_DEACTIVATED = 'account.deactivated';

    public const DEACTIVATION_REQUESTED = 'deactivation.requested';

    public const DEACTIVATION_APPROVED = 'deactivation.approved';

    public const DEACTIVATION_REJECTED = 'deactivation.rejected';

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'metadata',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * Catat satu tindakan sensitif. Log ini tidak boleh diubah atau dihapus.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, Model $subject, array $metadata = []): self
    {
        return self::create([
            'actor_id' => Auth::id(),
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()->ip(),
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
