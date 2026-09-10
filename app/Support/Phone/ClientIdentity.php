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
