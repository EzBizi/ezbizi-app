<?php

use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `earned_statuses` moves from a personal dashboard preference to an
 * organization setting (spec §8.2).
 *
 * Nobody loses a choice they made — it stops being private to them. Where two
 * users in one organization disagree, the UNION is taken: under-counting
 * revenue is the worse error, an organization can narrow the list afterwards
 * once it exists as a setting, and a union is reversible where a guess is not
 * — nothing here can invent a status nobody chose, it can only keep every one
 * that somebody did.
 *
 * Runs the whole migration in platform scope (`TenantContext::runWithoutTenant`)
 * rather than per-organization: reading every organization's `User` rows and
 * writing every organization's `Setting` row both go through models that use
 * `BelongsToTenant`, which refuses to run with no tenant in context AND refuses
 * a lapsed organization's writes outside platform scope (§13). A one-time
 * migration preserving a choice someone already made is not the kind of write
 * §13 is guarding against, so it is exempted the same way staff repair is.
 *
 * Promoting this setting also gains an audit trail the per-user preference
 * never had — going forward, a change here is logged as `settings.updated`
 * (SettingsController::update(), :102); the dashboard-preference write it
 * replaces was never audited at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(TenantContext::class)->runWithoutTenant(function () {
            $byOrg = [];

            User::query()->whereNotNull('preferences')->cursor()->each(function (User $user) use (&$byOrg) {
                foreach ((array) ($user->preferences['dashboard.glances'] ?? []) as $glance) {
                    if (($glance['key'] ?? null) !== 'jobs') {
                        continue;
                    }

                    foreach ((array) ($glance['options']['earned_statuses'] ?? []) as $status) {
                        if (is_string($status)) {
                            $byOrg[$user->organization_id][$status] = true;
                        }
                    }
                }
            });

            $resolver = app(SettingsResolver::class);

            foreach ($byOrg as $organizationId => $statuses) {
                $organization = Organization::find($organizationId);

                if ($organization === null) {
                    continue;
                }

                $resolver->set('jobs.earned_statuses', $organization, array_keys($statuses));
            }
        });
    }

    public function down(): void
    {
        DB::table('settings')->where('key', 'jobs.earned_statuses')->delete();
    }
};
