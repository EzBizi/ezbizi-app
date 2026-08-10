<?php

namespace App\Support\Settings;

use Illuminate\Database\Eloquent\Model;

/**
 * The settings the CLIENT needs in order to draw a date (plan §6).
 *
 * ── Why a short list rather than the whole registry ──────────────
 *
 * /me is fetched on every launch and again on every scope change, and it is on
 * the path to the first frame. Shipping all thirty settings there would put a
 * franchise's tax rate and its password policy on a payload the sign-in screen
 * waits for, to serve two of them. The settings SCREEN already has its own
 * endpoint for the full set, resolved with sources and permissions; this is the
 * handful the rest of the app formats with.
 *
 * ── Why exactly these two ────────────────────────────────────────
 *
 * A key earns a place here when something on screen reads it. `date_format` and
 * `time_format` are read by the client's formatStamp — the one function four
 * screens share to write a timestamp — and by the settings screen's own time
 * pickers.
 *
 * `week_starts_on` and `measurement_units` are deliberately absent. There is no
 * calendar and no quantity field in the client yet, so nothing would read them,
 * and adding them here would recreate the exact failure this work exists to fix:
 * a value that travels, looks live, and changes nothing. They join this list on
 * the day something formats from them, which costs one line each.
 */
class DisplaySettings
{
    /**
     * @var string[] registry keys carried on /me, in the order they are sent.
     *
     * Names, not shapes: the values are whatever the registry says they are, so
     * an option added to `date_format` reaches the client without a change here.
     * Whether the client can DRAW a new option is the client's problem to
     * declare, and it does — see DisplayFormats in the Flutter app.
     */
    public const KEYS = ['date_format', 'time_format'];

    public function __construct(
        private readonly SettingsResolver $resolver,
    ) {}

    /**
     * The resolved values for one scope, cascade already applied.
     *
     * @param  \App\Models\Organization|\App\Models\Company|\App\Models\Franchise  $scope
     * @return array<string, mixed>
     */
    public function forScope(Model $scope): array
    {
        $resolved = [];

        foreach (self::KEYS as $key) {
            // getOrFail behaviour, via the resolver: a key listed here that the
            // registry does not define throws at the first request rather than
            // sending the client a null it would silently format around.
            $resolved[$key] = $this->resolver->get($key, $scope);
        }

        return $resolved;
    }
}
