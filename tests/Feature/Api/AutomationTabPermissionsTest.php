<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The two Automations tabs are two different things to be trusted with.
 *
 * Jose, 2026-09-17: *"i should be able to grant access by sub tab as well ...
 * so this applies to every tab and it's sub tabs"*, and when asked whether it
 * was finished, *"do it for all modules., go ahead."*
 *
 * The split is not cosmetic here. BUILD hands somebody the catalogue, which is
 * a map of every trigger and action this installation can perform. YOUR
 * AUTOMATIONS is a reading surface, and a run log names records a person may
 * not be able to open directly — so the disclosure is on the other tab from
 * the construction.
 */
class AutomationTabPermissionsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    /**
     * THE POSITIVE CONTROL COMES FIRST, because HR's cost them an afternoon: a
     * role holding the right tab was refused by a platform layer answering
     * before any permission, and a suite made only of 403s reads exactly like
     * the feature working.
     */
    public function test_the_build_tab_grant_opens_the_catalogue(): void
    {
        $t = $this->provisionTenant('Alpha');

        $builder = $this->userWithRole($t, 'Rule writer', [
            'automations.view', 'automations.rules.view', 'automations.build.view',
        ]);

        $this->signedInAs($builder)
            ->getJson('/api/v1/automations/catalogue')
            ->assertOk();
    }

    /**
     * A hidden tab whose endpoints still answer is a decoration, and the URL is
     * one paste away from anybody who has ever seen the pane.
     */
    public function test_without_the_build_tab_its_endpoints_are_refused(): void
    {
        $t = $this->provisionTenant('Alpha');

        $reader = $this->userWithRole($t, 'Rule reader', [
            'automations.view', 'automations.rules.view',
        ]);

        $this->signedInAs($reader)
            ->getJson('/api/v1/automations/catalogue')
            ->assertForbidden();

        $this->signedInAs($reader)
            ->getJson('/api/v1/automations/trigger-fields?trigger=job.created')
            ->assertForbidden();
    }

    /**
     * AND THE LIST SURVIVES IT. This is the assertion that would have caught the
     * lazy version of this change — a blanket middleware over the whole route
     * block takes the rules away from everybody who was only meant to lose the
     * builder, and every other test here would still pass.
     */
    public function test_a_reader_without_the_builder_still_sees_their_rules(): void
    {
        $t = $this->provisionTenant('Alpha');

        $reader = $this->userWithRole($t, 'Rule reader', [
            'automations.view', 'automations.rules.view',
        ]);

        $this->signedInAs($reader)->getJson('/api/v1/automations')->assertOk();
    }
}
