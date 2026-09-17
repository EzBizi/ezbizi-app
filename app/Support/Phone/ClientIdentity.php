<?php

namespace App\Support\Phone;

use App\Models\User;

/**
 * A Voice SDK identity: who, and which of their devices.
 *
 * ── Why the device is part of it ─────────────────────────────────
 *
 * `<Dial>` makes exactly ONE call leg per `<Client>` noun. Measured on
 * 2026-09-10 against three inbound calls: three legs each, one per member of
 * the ring group, never two for the same person — so an identity that names
 * only a person can only ever ring one of that person's devices, whichever
 * Twilio picks. Jose had the desk open on a PC and the app on an iPhone, both
 * registered, and only the iPhone rang.
 *
 * The fix is not to hope Twilio fans out; its `<Client>` documentation says
 * nothing about that, and the "ten devices all ring" material is about SIP
 * registration, a different mechanism. It is to name each device and let a
 * Dial hold up to ten of them, which IS documented.
 *
 * ── The shape ────────────────────────────────────────────────────
 *
 *     019fd712…c51                 a person, no device named
 *     019fd712…c51_7f3a9b…         that person's Windows desk
 *
 * Alphanumeric and underscore only, which is all Twilio allows. The user half
 * is a UUID with its hyphens removed and therefore never contains an
 * underscore, so the split is unambiguous in both directions.
 *
 * The bare form is still minted for a client that sends no device id — an
 * older build, mid-rollout. That is a real fallback and not a tolerant reader:
 * such an identity is unambiguous, it simply names one endpoint the way every
 * identity did before.
 */
final class ClientIdentity
{
    public static function of(User $user, ?string $deviceId = null): string
    {
        $person = self::clean((string) $user->id);
        $device = self::clean((string) $deviceId);

        return $device === '' ? $person : $person.'_'.$device;
    }

    /**
     * The user half of an identity, however it was minted.
     *
     * Used to turn `client:…` back into a person — for an extension's caller
     * ID, and for anything else that has an identity and needs to know whose
     * it is. Splitting on the underscore is safe because the user half cannot
     * contain one.
     */
    public static function userPartOf(string $identity): string
    {
        $at = strpos($identity, '_');

        return $at === false ? $identity : substr($identity, 0, $at);
    }

    /**
     * WHICH DEVICE an identity names, or '' when it names only a person.
     *
     * The mirror of [userPartOf], and safe for the same reason: the person
     * half is a hyphen-stripped UUID and cannot contain an underscore, so the
     * first underscore is the only split there is.
     *
     * Wanted because a leg's `to` is the only record of which of somebody's
     * phones Twilio is ringing, and "stop my OTHER phones" has to spare the
     * one that asked.
     */
    public static function devicePartOf(string $identity): string
    {
        $at = strpos($identity, '_');

        return $at === false ? '' : substr($identity, $at + 1);
    }

    /**
     * The user id an identity names, in the shape the database stores it.
     *
     * `of()` strips the hyphens because Twilio allows only alphanumerics and
     * underscore in an identity, so the person half is a UUID minus its four
     * hyphens and nothing else — put them back at the fixed offsets and it is
     * the id again. Null for anything that is not 32 hex characters, which is
     * the honest answer for an identity minted by something other than this
     * class.
     *
     * Wanted because a webhook that learns WHICH identity answered a call has
     * a person to record and only a string to go on; the alternative is a
     * scan of every user comparing hyphen-stripped ids.
     */
    public static function uuidOf(string $identity): ?string
    {
        $person = self::userPartOf($identity);

        if (preg_match('/^[0-9a-fA-F]{32}$/', $person) !== 1) {
            return null;
        }

        return strtolower(implode('-', [
            substr($person, 0, 8),
            substr($person, 8, 4),
            substr($person, 12, 4),
            substr($person, 16, 4),
            substr($person, 20, 12),
        ]));
    }

    /** Whether this identity belongs to this person, device or no device. */
    public static function belongsTo(string $identity, User $user): bool
    {
        return self::userPartOf($identity) === self::clean((string) $user->id);
    }

    private static function clean(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', str_replace('-', '', $value)) ?? '';
    }
}
