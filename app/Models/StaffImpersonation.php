<?php

namespace App\Models;

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
 * Retained 12 months (§10) — longer than ordinary audit, because the question
 * it answers usually arrives late.
 */
class StaffImpersonation extends Model
{
    use HasFactory;
    use HasUuids;

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
