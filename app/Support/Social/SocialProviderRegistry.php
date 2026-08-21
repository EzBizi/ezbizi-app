<?php

namespace App\Support\Social;

final class SocialProviderRegistry
{
    public function __construct(private readonly SocialOAuthDrivers $drivers) {}

    /** @return list<array{value: string, label: string, usable: bool, unusable_because: ?string, connects_by: string, start_path: string}> */
    public function catalogue(): array
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
            ];
        }, SocialProvider::all());
    }

    public function notReadyBecause(SocialProvider $provider): ?string
    {
        if ($provider === SocialProvider::Bluesky) {
            return null;
        }

        return $this->drivers->for($provider)?->notReadyBecause();
    }
}
