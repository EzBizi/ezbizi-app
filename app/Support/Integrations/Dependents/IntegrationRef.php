<?php

namespace App\Support\Integrations\Dependents;

use Illuminate\Database\Eloquent\Model;

/**
 * The connection being disconnected, as every counter sees it.
 *
 * `kind` is social | phone | workspace. `provider` is the SocialProvider value
 * for social rows, 'twilio' for phone, 'google_workspace' for workspace.
 * `lastOfProvider` is true when the organization has no other connection of the
 * same provider — only then do rules that need "a Workiz account" (rather than
 * this one by id) stop.
 */
final class IntegrationRef
{
    public function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $id,
        public readonly string $organizationId,
        public readonly Model $model,
        public readonly bool $lastOfProvider,
    ) {}
}
