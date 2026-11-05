<?php

namespace App\Support\Social;

final class SocialProviderRegistry
{
    public function __construct(private readonly SocialOAuthDrivers $drivers) {}

    /** @return list<array{value: string, label: string, usable: bool, unusable_because: ?string, connects_by: string, start_path: string, permission: string}> */
    /**
     * @param  bool  $withDataSources  Integrations asks for every provider - a data
     *                                 source with nothing connected (Facebook Ads)
     *                                 had no card and so no Connect (2026-10-14).
     *                                 The composer keeps the publishing list.
     */
    public function catalogue(bool $withDataSources = false): array
    {
        return array_map(function (SocialProvider $provider) {
            $why = $this->notReadyBecause($provider);

            return [
                'value'            => $provider->value,
                'label'            => $provider->label(),
                'usable'           => $why === null,
                'unusable_because' => $why,
                'connects_by'      => $provider->connectsBy(),
                'start_path'       => $provider->startPath(),

                /*
                 * What a person must hold to connect this one (2026-09-23).
                 *
                 * Added for Settings > Integrations, on Jose's instruction that
                 * "the point of the Integration tab by itself is to be able to
                 * connect stuff from here, not just list it". That screen draws
                 * a Connect control per provider and cannot offer one that
                 * answers 403 - §4's rule that a control which is offered must
                 * work - so it needs the answer `connectPermission()` already
                 * holds.
                 *
                 * On the wire rather than copied into the client, for the
                 * reason SocialProvider's own docblock gives for keeping the
                 * map in one place: a second map elsewhere drifts into a wrong
                 * control on a customer's screen with nothing failing.
                 */
                'permission'       => $provider->connectPermission(),
                // The card this one folds into: its sign-in brings it, so
                // Integrations draws no card of its own for it.
                'signed_in_with'   => $provider->signIn() === $provider ? null : $provider->signIn()->value,
            ];
        }, $withDataSources ? SocialProvider::cases() : SocialProvider::all());
    }

    public function notReadyBecause(SocialProvider $provider): ?string
    {
        if ($provider === SocialProvider::Bluesky) {
            return null;
        }

        return $this->drivers->for($provider)?->notReadyBecause();
    }
}
