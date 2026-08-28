<?php

namespace Tests\Feature\Api;

use App\Models\Franchise;
use App\Support\Jobs\EarnedStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `jobs.earned_statuses` is an organization setting, not a per-user
 * dashboard preference (spec §8.2, plan Task 1).
 *
 * The brief this test was written from calls
 * `patchJson('/api/v1/settings/organization', [...])`, which is not a route.
 * The real endpoint is `PATCH /api/v1/settings/{key}` — see
 * SettingsController::update() and routes/api.php — and it requires
 * `scope_type`/`scope_id` on the query string (SettingsController::resolveScope()
 * validates both as `required`); every other feature test that PATCHes a
 * setting (e.g. DashboardTest::test_a_choice_no_longer_offered_is_dropped...)
 * supplies them the same way.
 */
class JobEarnedStatusesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_earned_statuses_are_an_organization_answer_not_a_personal_one(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $this->assertSame([], EarnedStatuses::for($alpha['franchise']));
        });

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.earned_statuses?scope_type=organization&scope_id='
                .$alpha['org']->id, ['value' => ['completed']])
            ->assertOk();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $fresh = Franchise::findOrFail($alpha['franchise']->id);

            $this->assertSame(['completed'], EarnedStatuses::for($fresh));
            $this->assertTrue(EarnedStatuses::isEarned('completed', $fresh));
            $this->assertFalse(EarnedStatuses::isEarned('scheduled', $fresh));
        });
    }

    public function test_a_status_the_organization_no_longer_publishes_is_dropped(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.earned_statuses?scope_type=organization&scope_id='
                .$alpha['org']->id, ['value' => ['completed', 'invented']])
            ->assertOk();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $fresh = Franchise::findOrFail($alpha['franchise']->id);

            // 'invented' is not in jobs.statuses, so it cannot mean earned.
            $this->assertNotContains('invented', EarnedStatuses::for($fresh));
        });
    }

    /**
     * Pins the review finding on SettingRegistry.php:101-108: `jobs.earned_statuses`
     * is TYPE_JSON, not TYPE_LIST, because TYPE_LIST's castList() throws on the
     * empty-array default this setting requires. But castJson() only checks
     * is_array() — unlike castList() it does not enforce array_is_list() or
     * uniqueness — so a string-keyed map with a repeated value passes PATCH
     * validation exactly like a plain list would, and array_intersect() in
     * EarnedStatuses::for() matches by value, not key, so it would carry both
     * entries through untouched without the defensive array_unique() there.
     */
    public function test_a_duplicated_or_map_shaped_stored_value_is_deduped_to_a_plain_list(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/settings/jobs.earned_statuses?scope_type=organization&scope_id='
                .$alpha['org']->id, ['value' => ['a' => 'completed', 'b' => 'completed']])
            ->assertOk();

        $this->inTenant($alpha['org'], function () use ($alpha) {
            $fresh = Franchise::findOrFail($alpha['franchise']->id);

            $earned = EarnedStatuses::for($fresh);

            $this->assertSame(['completed'], $earned);
            $this->assertTrue(array_is_list($earned));
        });
    }
}
