<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * One audit record (plan §10).
 *
 * ── Append-only, and why there's no hash chain ───────────────────
 *
 * The review asked for "integrity/tamper controls", and the obvious answer is a
 * hash chain — each row carrying a hash of its predecessor, so editing one
 * breaks the chain visibly.
 *
 * I'm not building that, deliberately. A hash chain only detects tampering if
 * the chain head is anchored somewhere the attacker cannot reach. Store the
 * chain in the same table it protects and anyone with database access simply
 * recomputes it — the chain proves nothing while looking like it does, which is
 * worse than not having one. It also serialises audit writes per tenant to avoid
 * two events claiming the same predecessor.
 *
 * What actually helps, and is what's implemented:
 *
 *   1. No update or delete path exists in code (enforced below).
 *   2. No `updated_at` column — a row that can be updated is not an audit record.
 *   3. The application database user should hold INSERT and SELECT only:
 *          REVOKE UPDATE, DELETE ON ezbizi.audit_events FROM 'ezbizi'@'localhost';
 *      That makes tampering impossible through the app under any bug, rather
 *      than merely detectable afterwards.
 *   4. Backups (§15) give an off-box copy to compare against.
 *
 * Real tamper-evidence needs an append-only external store — object-lock S3 or a
 * SIEM. `delivered_at` exists so that can be added without a redesign. Until it
 * is, this docblock is the honest statement of what the guarantee is.
 */
class AuditEvent extends Model
{
    use HasFactory;
    use HasUuids;

    public const UPDATED_AT = null;   // append-only
    public const CREATED_AT = null;   // occurred_at is the real timestamp

    protected $fillable = [
        'organization_id', 'actor_type', 'actor_id', 'actor_label',
        'action', 'target_type', 'target_id', 'target_label',
        'scope_type', 'scope_id', 'changes', 'metadata',
        'correlation_id', 'causation_id', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'changes'      => 'array',
            'metadata'     => 'array',
            'occurred_at'  => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public const ACTOR_USER   = 'user';
    public const ACTOR_STAFF  = 'staff';
    public const ACTOR_SYSTEM = 'system';

    protected static function booted(): void
    {
        // Deliberately NOT tenant-scoped via BelongsToTenant: audit rows exist for
        // platform events too, and scoping reads would hide staff activity from
        // the very queries meant to review it. Scope at the query site instead.

        static::updating(function () {
            throw new RuntimeException(
                'Audit events are append-only. Correcting one means writing a new '
                .'event describing the correction, never editing history.'
            );
        });

        static::deleting(function () {
            throw new RuntimeException(
                'Audit events are append-only. Expiry happens via the retention '
                .'command (plan §10), which is itself audited.'
            );
        });
    }
}
