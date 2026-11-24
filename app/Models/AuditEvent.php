<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
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

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::elsewhere('ezbizi:expire-audit',
            'Four windows rather than one, because the categories answer different '
            .'questions: three months of business activity, three of developer events, one '
            .'of crashes and errors, twelve of platform-staff access to client data (§4.2). '
            .'The generic sweep cannot express that, so this table keeps the command that '
            .'can — and that command is the only thing permitted to delete from here.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('actor_label', PersonalDataEntry::IDENTITY,
                'The name and email address of whoever acted, written at the time so the '
                .'entry survives their account being removed. This is the copy that makes '
                .'erasure here an anonymise rather than a delete.',
                /*
                 * The one write into an append-only table, and it is the write
                 * this class's own retention sentence has always described:
                 * "erasing somebody from here means anonymising the actor label
                 * rather than deleting rows."
                 *
                 * The entry stays, so the count of what happened is unchanged and
                 * the sequence is unbroken; what goes is the name. "Somebody
                 * deleted this job on the 4th" is still evidence — of the act, of
                 * the time, of the target. It is the fact of the action that the
                 * log exists to hold, and the label was only ever the convenience
                 * of not having to join.
                 */
                Erasure::erase('Former staff')),
            new PersonalDataEntry('target_label', PersonalDataEntry::IDENTITY,
                'What was acted ON, by name — which for a user or customer is a person.',
                Erasure::erase('Removed record')),
            new PersonalDataEntry('changes, metadata', PersonalDataEntry::CONTENT,
                'The before and after of what changed. Secrets are redacted on the way in '
                .'and sensitive values are never copied here at all, but ordinary personal '
                .'data — a phone number being corrected — is recorded, because that is '
                .'what the entry is for.',
                /*
                 * Kept, and this is the uncomfortable one, so the reasoning is
                 * spelt out rather than asserted.
                 *
                 * `changes` holds the OLD value of whatever was edited, which for
                 * an edit to a phone number is a phone number. Blanking it would
                 * be the thorough answer and it would also empty the column that
                 * makes the entry worth having: an audit row saying "the customer
                 * was updated" and refusing to say what changed is not a weaker
                 * audit trail, it is a list of timestamps.
                 *
                 * What makes the keep defensible is the retention above rather
                 * than any argument about necessity. This is the only personal
                 * data in the system with a hard expiry measured in months — it
                 * ages out on its own, without anybody asking, which is more than
                 * the tables that erase on request can say. A purge is not the
                 * mechanism that reaches it; §10 is, and §10 already runs nightly.
                 */
                Erasure::keep('The before-and-after IS the entry; an audit row that cannot '
                    .'say what changed records nothing. It is not erased on request and '
                    .'does not need to be — it is the one place in this system where '
                    .'personal data expires on a clock, within three months, under the §10 '
                    .'retention this table states.')),
            new PersonalDataEntry('metadata.ip, metadata.user_agent',
                PersonalDataEntry::TECHNICAL,
                'Inside the metadata rather than in columns of their own, and only on the '
                .'events where they mean something: signing in, changing a password, '
                .'enrolling a second factor. They are what makes "was that me?" '
                .'answerable.',
                Erasure::keep('Inside the metadata JSON rather than in a column, so it is '
                    .'covered by the same expiry as the rest of that column and by the same '
                    .'reasoning. Listed separately because a reader looking for "do you '
                    .'keep my IP address" will not find it under "metadata".')),
        ];
    }

    /**
     * Entries where this person is the actor, or the thing acted on.
     *
     * Both halves, and they are stored differently: the actor is a pair of
     * columns, the target is a polymorphic pair, and only this class knows that.
     * `withoutGlobalScopes` is not needed — there is no tenant scope here, by the
     * deliberate decision recorded in `booted()` — but the organization IS
     * narrowed, because a bare query on this table crosses tenants and the purge
     * must not.
     *
     * Which makes the brackets round the disjunction load-bearing HERE in a way
     * they are not on the tenant-scoped tables. Elsewhere a flat `orWhere` is
     * rescued by `Builder::callScope()`, which groups the pre-existing wheres
     * before a global scope appends its own. This organization predicate is
     * written by hand, in the same chain, with no scope to trigger that — so
     * `org = X and actor = A or target = A` would be exactly what it looks like:
     * every organization's entries naming this person, walked by a purge.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return static::query()
            ->where('organization_id', $subject->getAttribute('organization_id'))
            ->where(function (Builder $q) use ($subject) {
                $q->where(fn (Builder $a) => $a
                    ->where('actor_type', self::ACTOR_USER)
                    ->where('actor_id', $subject->getKey()))
                  ->orWhere(fn (Builder $t) => $t
                      ->where('target_type', $subject::class)
                      ->where('target_id', $subject->getKey()));
            });
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
