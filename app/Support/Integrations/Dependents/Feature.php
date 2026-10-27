<?php

namespace App\Support\Integrations\Dependents;

use App\Support\Social\SocialProvider;

/** One line of "what this disconnect costs": a feature and how hard it is hit. */
final class Feature
{
    public const BREAKS = 'breaks';

    public const DEGRADES = 'degrades';

    /** @return array{feature: string, severity: 'breaks'|'degrades'} */
    public static function breaks(string $feature): array
    {
        return ['feature' => $feature, 'severity' => self::BREAKS];
    }

    /** @return array{feature: string, severity: 'breaks'|'degrades'} */
    public static function degrades(string $feature): array
    {
        return ['feature' => $feature, 'severity' => self::DEGRADES];
    }

    /** Breaks when this is the organization's last account of the provider; degrades when another remains. */
    public static function lastBreaks(IntegrationRef $ref, string $feature): array
    {
        return $ref->lastOfProvider ? self::breaks($feature) : self::degrades($feature);
    }

    /** The provider's human name, or the raw key when it is not a social provider. */
    public static function providerLabel(IntegrationRef $ref): string
    {
        return match ($ref->provider) {
            'twilio' => 'the phone account',
            'google_workspace' => 'Google Workspace',
            default => SocialProvider::tryFrom($ref->provider)?->label() ?? $ref->provider,
        };
    }
}
