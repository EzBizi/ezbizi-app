<?php

namespace App\Models;

use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
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
class AuditEvent extends Model implements HoldsPersonalData
{
    use HasFactory;
    use HasUuids;

    // ── §9 data inventory ────────────────────────────────────────

    /*
     * The table most likely to be left off an inventory, and the one §9 names.
     *
     * "Personal data also hides in audit logs" is on its list, and this table is
     * the clearest case in the whole schema: every row carries a name and an
     * email address, captured deliberately so that "who did this" survives the
     * actor's deletion. That is the right design and it is also a standing
     * tension with erasure, which is precisely why it has to be written down
     * where somebody answering a rights request will see it.
     */
    public static function personalDataPurpose(): string
    {
        return 'What everybody did, and when. §2.2 requires every action to be logged, and '
            .'the name and address of the person who took it are captured on the row itself '
            .'so the entry still says who it was after their account is gone.';
    }

    public static function personalDataRetention(): string
    {
        return 'Append-only, with retention set by §10 and enforced by the scheduled '
            .'expiry command. Entries cannot be edited or removed individually — a log that '
            .'could be would not be evidence of anything — so erasing somebody from here '
            .'means anonymising the actor label rather than deleting rows.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('actor_label', PersonalDataEntry::IDENTITY,
                'The name and email address of whoever acted, written at the time so the '
                .'entry survives their account being removed. This is the copy that makes '
                .'erasure here an anonymise rather than a delete.'),
            new PersonalDataEntry('target_label', PersonalDataEntry::IDENTITY,
                'What was acted ON, by name — which for a user or customer is a person.'),
            new PersonalDataEntry('changes, metadata', PersonalDataEntry::CONTENT,
                'The before and after of what changed. Secrets are redacted on the way in '
                .'and sensitive values are never copied here at all, but ordinary personal '
                .'data — a phone number being corrected — is recorded, because that is '
                .'what the entry is for.'),
            new PersonalDataEntry('metadata.ip, metadata.user_agent',
                PersonalDataEntry::TECHNICAL,
                'Inside the metadata rather than in columns of their own, and only on the '
                .'events where they mean something: signing in, changing a password, '
                .'enrolling a second factor. They are what makes "was that me?" '
                .'answerable.'),
        ];
    }

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
