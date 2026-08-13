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
 * ── RESERVED IS THE LONGER LIST, AND IT STAYS LONGER ─────────────
 *
 * A key can be reserved without being asked, and `gender` is now one of them.
 * The question is gone from onboarding (see ASKED), and the key is still refused
 * to a template — because `users.gender` exists, people have already answered
 * it, and a tenant declaring a field of their own called `gender` would be
 * writing a second value beside the one already on the profile. Withdrawing a
 * QUESTION and releasing a COLUMN NAME are different acts, and only the first
 * one happened.
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
     * key => what to call it, and what it IS.
     *
     * ── Two strings, because they do two different jobs ──────────────
     *
     * `is` completes the sentence "it is …" inside the refusal, which is what
     * makes the refusal readable: "'phone' is already part of every contract: it
     * is their phone number, and it lives on their profile". That phrasing is
     * right there and wrong everywhere else.
     *
     * It used to be the only string here, and the catalogue published it under
     * the name `is` with no label beside it — so the client, having nothing
     * better, used it as one. The designer drew a locked chip reading "their
     * middle name" and the form preview drew a box titled "the email address on
     * their account". Both are the server's own words; neither is a label. That
     * is what a fallback chain does when the answer it wants is simply absent,
     * and the fix is to supply the answer rather than to teach the client to
     * repair a phrase.
     *
     * `label` is the noun phrase a person reads above the box. The two are one
     * entry, so they cannot drift apart.
     *
     * @var array<string, array{label: string, is: string}>
     */
    public const RESERVED = [
        'email'         => ['label' => 'Email address', 'is' => 'the email address on their account'],
        'name'          => ['label' => 'Display name',  'is' => 'their display name'],
        'first_name'    => ['label' => 'First name',    'is' => 'their first name'],
        'middle_name'   => ['label' => 'Middle name',   'is' => 'their middle name'],
        'last_name'     => ['label' => 'Last name',     'is' => 'their last name'],
        'phone'         => ['label' => 'Phone number',  'is' => 'their phone number'],
        'date_of_birth' => ['label' => 'Date of birth', 'is' => 'their date of birth'],
        'gender'        => ['label' => 'Gender',        'is' => 'their gender'],
    ];

    /**
     * The six onboarding ACTUALLY asks, and whether each is required.
     *
     * `RESERVED` is the larger list — every identity column the profile owns, so
     * that a template cannot declare a second home for any of them. This is the
     * subset onboarding puts on screen, and it is the ONLY list that may be
     * described as "always asked": a screen drawing RESERVED under that heading
     * is naming questions nobody is ever asked.
     *
     * The two that are reserved but not asked are `name` and `gender`. `name` is
     * DERIVED — ProfileController recomposes it from the parts on every save, so
     * a form that asked for it separately would be asking somebody to maintain a
     * value the application overwrites. `gender` is a question this software has
     * stopped asking; see below.
     *
     * ── GENDER IS NOT ASKED, and the column is untouched ─────────
     *
     * The owner's instruction, and it is the same judgement the migration that
     * created the column already recorded about software that "refuses to work
     * until somebody states their gender": nothing in employment onboarding
     * turns on the answer, so the form has no business demanding a place to put
     * it. Taking it out of here removes the QUESTION from onboarding, from the
     * document's list of the person, and from the merge vocabulary.
     *
     * IT DOES NOT REMOVE ANYBODY'S ANSWER. `users.gender` still exists and still
     * holds what people typed under the old behaviour; "Your account" still
     * shows and edits it, ProfileController still accepts it, and the §9
     * inventory still declares it. Deleting collected data is a decision for
     * whoever runs the organization, taken through erasure, and never a side
     * effect of a form losing a box.
     *
     * ── MIDDLE NAME IS ASKED, AND IT IS OPTIONAL ─────────────────
     *
     * It was reserved-but-never-asked, which is the worst of both: a template
     * could not declare it, onboarding never collected it, and a body naming
     * `{{middle_name}}` was refused with a paragraph explaining there was
     * nothing to merge. So a contract that wanted somebody's full legal name
     * could not have one.
     *
     * `false` is the whole point of asking it. Plenty of people have no middle
     * name, and a required box is a form that cannot be submitted honestly —
     * which is answered by leaving it blank rather than by typing something
     * untrue to get past it.
     *
     * ── Requiredness is the PLATFORM's here, and it has to be ────
     *
     * §2.1 says the tenant defines the fields, and everywhere else in this
     * module they do. Not these: a template is refused these keys entirely
     * (see RESERVED above), so if the platform does not say a date of birth is
     * required then no tenant can ever make it so. That is the trade the
     * one-home rule costs, and it is settled here — which is why the flag is
     * PUBLISHED beside each key on the contracts catalogue rather than left for
     * a screen to guess at. A form that draws "Middle name" identically to
     * "Last name" is a form that reads as demanding one.
     *
     * @var array<string, bool> key => required at submit
     */
    public const ASKED = [
        // Required, and never writable through onboarding: it is the address the
        // invitation was minted for and the identity somebody signs in with.
        // ProfileController refuses to change it for the same reason — changing
        // it without proving control of the new mailbox turns a session hijack
        // into a permanent takeover.
        'email'         => true,
        // Name order, because this is the order a form asks a name in and
        // `asked()` promises the order onboarding uses. Splitting the middle
        // name away from the two it sits between would put an optional box at
        // the bottom of the form, under the date of birth, where nobody reading
        // it would connect it to the name above.
        'first_name'    => true,
        'middle_name'   => false,
        'last_name'     => true,
        'phone'         => true,
        'date_of_birth' => true,
    ];

    /** @return string[] */
    public static function keys(): array
    {
        return array_keys(self::RESERVED);
    }

    /** @return string[] the six onboarding asks, in the order it asks them */
    public static function asked(): array
    {
        return array_keys(self::ASKED);
    }

    public static function isAsked(string $key): bool
    {
        return array_key_exists($key, self::ASKED);
    }

    /**
     * Whether onboarding refuses to be submitted without this one.
     *
     * A key nobody asks is not required, which is the only sensible answer for
     * a caller that hands this an arbitrary reserved key — but it is `false`
     * here for the reason `isAsked` exists rather than as a shrug: requiredness
     * is a property of a QUESTION, and a key that is not asked has none.
     */
    public static function isRequired(string $key): bool
    {
        return self::ASKED[$key] ?? false;
    }

    public static function claims(string $key): bool
    {
        return array_key_exists($key, self::RESERVED);
    }

    /** What a person reads above the box, and on the locked chip in the designer. */
    public static function labelFor(string $key): string
    {
        return self::RESERVED[$key]['label'];
    }

    /**
     * The fragment completing "it is …".
     *
     * For a sentence, and never for a label — a caller that wants words to put on
     * a control wants labelFor().
     */
    public static function describe(string $key): string
    {
        return self::RESERVED[$key]['is'];
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
            self::describe($key),
        );
    }
}
