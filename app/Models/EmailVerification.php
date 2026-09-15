<?php

namespace App\Models;

use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One request to confirm an email address, and the only reason this class exists.
 *
 * ── A table with no model is invisible, not exempt ───────────────
 *
 * Nothing here issues or redeems a verification — App\Services\Auth\EmailVerifications
 * does all of it through `DB::table('email_verifications')`, for the same reason
 * PasswordReset's service does: a confirmation is written before the account has
 * proved anything, and often before any tenant is in context.
 *
 * That worked, and it made the table unreachable by every §9 and §10 mechanism
 * at once — DataInventory, the Anonymiser and `ezbizi:expire-data` all walk
 * app/Models, so a table absent from that walk is never mentioned rather than
 * reported missing. This model is the cheapest way to be seen by all three, and
 * it stays a declaration rather than a writer: the service keeps its query
 * builder.
 *
 * PasswordReset says all of this first and says it better; this table repeated
 * the mistake four days later, which is the argument for the guard rather than
 * for the comment. RetentionTest::test_every_table_in_the_schema_has_been_decided_about
 * is what caught it, before the table ever reached production.
 */
class EmailVerification extends Model implements HoldsPersonalData
{
    protected $table = 'email_verifications';

    /** UUIDs, written by the service. Nothing here generates a key. */
    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * Nothing is fillable, on purpose.
     *
     * This class is how the privacy machinery SEES the table, not a second way
     * to write to it. `token_hash` is a credential and `used_at` is what spends
     * it; App\Services\Auth\EmailVerifications owns both, and a mass-assignable
     * model beside it would be a way to confirm an address from somewhere that
     * has not thought about single use or expiry.
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
        return 'One row for each time somebody was asked to confirm an email address: which '
            .'account, which address the link was sent to, when, from what address, and '
            .'whether it was used. It is what answers "how do we know this mailbox is '
            .'theirs?" and "who asked for this?" after a confirmation nobody expected.';
    }

    /**
     * Three months, matching PasswordReset and the business audit window.
     *
     * The same number on purpose, because this row answers the same kind of
     * question as a reset request — "what happened on my account, and was it
     * me" — and two different clocks over one history means the confirmation
     * request is still readable when the log of what it did is gone, or the
     * other way round.
     *
     * The durable fact does not live here. `users.email_verified_at` is what
     * records that the address was proved, and it survives this row going; what
     * expires is the request, not the proof. A row that was never redeemed is
     * only recorded here, which is why this is not simply deleted on use.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'created_at',
            'Kept for three months so that "who asked for this confirmation, and from where" '
            .'can still be answered afterwards — the same window as the audit trail and as a '
            .'password reset. The link itself stops working within the day; what is kept '
            .'afterwards is the record that it was asked for. That an address WAS confirmed '
            .'is held on the account, not here, and is not affected by this expiring.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which account the confirmation was for. Not a name — a pointer to the record '
                .'that holds one — but it is what makes the row about a particular person.',
                Erasure::drop('The row is nothing but this person and a spent link. There is '
                    .'no record left to anonymise once the pointer to them is taken out, so '
                    .'blanking it would leave litter rather than an anonymous fact.')),
            new PersonalDataEntry('email', PersonalDataEntry::CONTACT,
                'The address the link was SENT to, recorded here rather than read back off '
                .'the account at redemption time. It can differ from the address the person '
                .'ends up with: somebody who signs up with a typo and then corrects it must '
                .'not be confirmed by the link that went to the typo, because that link '
                .'proves control of the wrong mailbox. So this is a second contact detail '
                .'about them, sometimes one they never owned.',
                Erasure::drop('Goes with the row. An address with no request attached to it '
                    .'records nothing and is still a record of where somebody could be '
                    .'reached.')),
            new PersonalDataEntry('requested_ip', PersonalDataEntry::TECHNICAL,
                'Where the request came from, which is the first thing asked for after a '
                .'confirmation nobody recognises.',
                Erasure::drop('Goes with the row it belongs to. An address with no request '
                    .'attached to it records nothing and is still a record of where somebody '
                    .'was.')),
            new PersonalDataEntry('token_hash', PersonalDataEntry::TECHNICAL,
                'The confirmation link, stored as a sha256 so the credential itself is never '
                .'held. It is not reversible and says nothing about the person.',
                Erasure::drop('Not personal data on its own, and listed because it is the '
                    .'rest of the row: it goes because the row goes.')),
        ];
    }

    /**
     * Confirmations issued for this person.
     *
     * A plain `user_id` rather than PasswordReset's subject_type/subject_id
     * pair, and the narrower shape is correct: only a self-serve signup produces
     * an unconfirmed account, and signup produces a User. Platform staff (§4.2)
     * are created by another member of staff and never travel this path, so
     * there is no second subject type for this to be polymorphic about.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    /**
     * How this table narrows to one organization: through the account it names.
     *
     * There is no `organization_id` here and there should not be — a
     * confirmation belongs to the person, and the row is written during signup
     * in the same transaction that creates their organization. Reading it back
     * through the user keeps one answer rather than two that can disagree.
     *
     * `withoutGlobalScopes()` because this runs to ANSWER a tenant question, not
     * from inside one: the inventory asks "how much of this is Alpha's" while no
     * tenant is in context, and the scoped query would return nothing at all.
     */
    public static function narrowToOrganization($query, string $organizationId)
    {
        return $query->whereIn('user_id', User::query()
            ->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->select('id'));
    }
}
