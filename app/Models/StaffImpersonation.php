<?php

namespace App\Models;

use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One occasion on which EzBizi staff reached into a customer's data (§4.2).
 *
 * Written for EVERY tier, including the Lead Developer whose ordinary activity
 * is otherwise unaudited. That exemption covers development noise; it does not
 * cover looking at a customer's records. If a client asks who opened their data
 * and when, this table is the answer, and an answer with holes in it is worse
 * than no answer at all.
 *
 * Kept for good (§10), which is a decision rather than an omission — the reason
 * is on retention() below.
 */
class StaffImpersonation extends Model implements StatesRetention
{
    use HasFactory;
    use HasUuids;

    /**
     * Forever, and the owner decided it.
     *
     * "Retained 12 months (§10)" stood here and nothing enforced it. There is no
     * deleter for this table at all; ExpireImpersonations only stamps `ended_at`
     * on a session that ran past its expiry, and the twelve months AuditEvent's
     * policy names is a window ezbizi:expire-audit applies to `audit_events` rows
     * filtered by actor_type — a different table, so even that command would have
     * left these rows standing. A number in prose that nothing reads is exactly
     * what RetentionPolicy exists to stop, so the sentence is gone rather than
     * reworded into a window.
     *
     * The answer is not a shorter number either, and that is the substance of it:
     * this is the trail of platform staff reaching into a customer's records, so
     * it is the one table you do not sweep. A trail that expires is a trail that
     * has stopped answering the question it exists for, and that question — who
     * opened my data, and when — is asked late almost by definition.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The record of EzBizi staff opening your organization\'s data: who opened it, '
            .'when, why, and whether you approved it (§4.2). Kept for as long as the '
            .'organization is, because nobody asks this question at the time — it is asked '
            .'months later, and by then an expiring record would have nothing to say.'
        );
    }

    protected $fillable = [
        'staff_id', 'organization_id', 'reason',
        'expires_at', 'started_at', 'ended_at',
        'approved_by_user_id', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'  => 'datetime',
            'started_at'  => 'datetime',
            'ended_at'    => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function staff(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'staff_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Still usable?
     *
     * Expiry is checked on every request rather than only at creation — a
     * session left open in a browser tab must stop working on its own, or
     * "time-limited" means nothing.
     */
    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    public function wasApproved(): bool
    {
        return $this->approved_at !== null;
    }

    public function end(): void
    {
        if ($this->ended_at === null) {
            $this->update(['ended_at' => now()]);
        }
    }
}
