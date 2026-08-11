<?php

namespace App\Support\Contracts;

/**
 * The facts about a person that a contract template may NOT declare
 * (EZBIZI-HR-PLAN.md, "The common core" and "Records, and the one-home rule").
 *
 * ── Why a template is refused these keys ─────────────────────────
 *
 * The plan's one-home rule: core identity "lives on the user profile and is not
 * copied. HR points at it." A template declaring `date_of_birth` would create a
 * second home for one fact — the profile's and the contract's — and there is no
 * version of that which stays consistent. One of the two gets corrected and the
 * other silently becomes wrong, and nothing on screen says which is which.
 *
 * They are also always asked regardless of contract, because an invitation
 * cannot be addressed without some of them. So declaring one is not merely
 * duplicative: it asks a question the onboarding form is already asking, in the
 * same form, twice.
 *
 * ── Where this list comes from ───────────────────────────────────
 *
 * Not from a hand-copied sentence in the plan, and not from `User::$fillable`
 * either — fillable holds `password` and `role_id`, which are not identity and
 * would produce a refusal nobody could make sense of. It is the set of identity
 * columns the USER PROFILE owns, which is the rule the plan actually states.
 * Each key below IS that column's name, and the sentence beside it is what the
 * person who collided with it reads.
 *
 * The plan names six (email, first/last name, phone, date of birth, gender);
 * `middle_name` and `name` are here for exactly the same reason rather than as a
 * broader guess — both are profile-owned columns holding the person's name, and
 * a template field for either would be the same duplicate home. `name` is the
 * display name the whole application shows; a template that wants somebody
 * else's name asks for it under its own key (`emergency_contact_name`), which
 * this does not touch.
 *
 * ── Not a permanent ban on the CONCEPT ───────────────────────────
 *
 * A tenant who needs the date of birth on the printed contract gets it: the
 * merge reads the profile. What is refused is a second FIELD storing a second
 * copy of it, which is a different thing and the refusal message says so.
 */
final class CommonCore
{
    /**
     * key => the sentence a person reads when their template collides with it.
     *
     * The sentence names the column's home, because "reserved" on its own tells
     * somebody a rule exists without telling them where their answer went.
     *
     * @var array<string, string>
     */
    public const RESERVED = [
        'email'         => 'the email address on their account',
        'name'          => 'their display name',
        'first_name'    => 'their first name',
        'middle_name'   => 'their middle name',
        'last_name'     => 'their last name',
        'phone'         => 'their phone number',
        'date_of_birth' => 'their date of birth',
        'gender'        => 'their gender',
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::RESERVED);
    }

    public static function claims(string $key): bool
    {
        return array_key_exists($key, self::RESERVED);
    }

    /**
     * The refusal, written for the person who just typed the key.
     *
     * It says what the field already is, where it lives, and what to do instead
     * — because "reserved key" alone leaves somebody renaming it to `dob` and
     * getting the duplicate home this refusal exists to prevent.
     */
    public static function refusalFor(string $key): string
    {
        return sprintf(
            "'%s' is already part of every contract: it is %s, and it lives on their profile "
            .'rather than on a template. Onboarding asks for it whatever contract is used, and '
            .'the document can merge it in. Declaring it here would store a second copy that '
            .'nothing keeps in step with the first.',
            $key,
            self::RESERVED[$key],
        );
    }
}
