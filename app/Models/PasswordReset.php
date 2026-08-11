<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One password-reset request, and the only reason this class exists.
 *
 * ── A table with no model is invisible, not exempt ───────────────
 *
 * Nothing here is needed to issue or redeem a reset — App\Services\Auth\PasswordResets
 * does all of that through `DB::table('password_resets')`, deliberately, because
 * a reset is written before there is a tenant and often before there is a User
 * at all. That worked, and it made the table structurally unreachable by every
 * §9 and §10 mechanism in the system at once:
 *
 *   - DataInventory walks app/Models, so the inventory did not list it;
 *   - the Anonymiser walks the same set, so a purge left the row untouched —
 *     `subject_id` naming the person and `requested_ip` naming where they were;
 *   - `ezbizi:expire-data` walks it too, and rows here are marked used rather
 *     than deleted, so the table only ever grew.
 *
 * Three mechanisms, one blind spot, and none of them could report it: a table
 * absent from a derived walk does not error, it is simply never mentioned. The
 * model is the cheapest way to be seen by all three, and it stays a declaration
 * rather than a writer — the service keeps its query builder.
 *
 * RetentionTest::test_every_table_in_the_schema_has_been_decided_about is what
 * stops the next such table being found the same way, by somebody reading.
 */
class PasswordReset extends Model implements HoldsPersonalData
{
    protected $table = 'password_resets';

    /** UUIDs, written by the service. Nothing here generates a key. */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Nothing is fillable, on purpose.
     *
     * This class is how the privacy machinery SEES the table, not a second way
     * to write to it. `token_hash` is a credential and `used_at` is what spends
     * it; App\Services\Auth\PasswordResets owns both, and a mass-assignable
     * model beside it would be an invitation to write a reset from somewhere
     * that has not thought about single use.
     */
    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
        ];
    }

    // ── §9 data inventory ────────────────────────────────────────

    public static function personalDataPurpose(): string
    {
        return 'One row for each time somebody asked to reset a password: which account, '
            .'when, from what address, and whether the link was used. It is what answers '
            .'"who requested this?" after a reset nobody expected.';
    }

    /**
     * Three months, matching the business audit window.
     *
     * Deliberately the same number, because this row answers the same kind of
     * question as an audit entry — "what happened on my account, and was it me"
     * — and two different clocks over one history means the reset request is
     * still readable when the log of what it did is gone, or the other way
     * round. `auth.password_reset` is written to the audit log when a token is
     * redeemed, under that window; a request that was never redeemed is only
     * recorded here, which is the reason this is not simply deleted on use.
     *
     * After that it is a spent sha256 and an IP address, kept about somebody
     * for no remaining purpose — which is the shape §10 exists to stop.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Kept for three months so that "who asked for this reset, and from where" can '
            .'still be answered after an unexpected password change — the same window as '
            .'the audit trail of what the reset then did. The link itself stops working '
            .'within the hour; what is kept afterwards is the record that it was asked for.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('subject_type, subject_id', PersonalDataEntry::IDENTITY,
                'Which account the reset was for. Not a name — a pointer to the record that '
                .'holds one — but it is what makes the row about a particular person.',
                Erasure::drop('The row is nothing but this person and a spent link. There is '
                    .'no record left to anonymise once the pointer to them is taken out, so '
                    .'blanking it would leave litter rather than an anonymous fact.')),
            new PersonalDataEntry('requested_ip', PersonalDataEntry::TECHNICAL,
                'Where the request came from, which is the first thing asked for after a '
                .'password change nobody recognises.',
                Erasure::drop('Goes with the row it belongs to. An address with no request '
                    .'attached to it records nothing and is still a record of where somebody '
                    .'was.')),
            new PersonalDataEntry('token_hash', PersonalDataEntry::TECHNICAL,
                'The reset link, stored as a sha256 so the credential itself is never held. '
                .'It is not reversible and says nothing about the person.',
                Erasure::drop('Not personal data on its own, and listed because it is the '
                    .'rest of the row: it goes because the row goes.')),
        ];
    }

    /**
     * Resets requested for this person.
     *
     * `subject_type` holds a fully-qualified class name, NOT a morph-map wire
     * name: App\Services\Auth\PasswordResets writes `$subject::class` with a
     * plain query builder, so there is no `morphTo` doing the translation on the
     * way in. Comparing against `getMorphClass()` here would match nothing at
     * all, silently, and the purge would report a clean sweep of a table it
     * never looked in.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey())
            : null;
    }

    /**
     * How this table narrows to one organization: through the account it names.
     *
     * There is no `organization_id` here, and there cannot be — a reset is
     * issued before any tenant is in context, and platform staff (§4.2) get
     * their resets from the same table. Same shape as ApiToken, for the same
     * reason: without this the inventory can only answer "we hold this and
     * cannot tell you how much".
     */
    public static function narrowToOrganization($query, string $organizationId)
    {
        return $query
            ->where('subject_type', User::class)
            ->whereIn('subject_id', User::query()
                ->withoutGlobalScopes()
                ->where('organization_id', $organizationId)
                ->select('id'));
    }
}
