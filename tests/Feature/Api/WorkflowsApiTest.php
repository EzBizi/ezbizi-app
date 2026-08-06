<?php

namespace Tests\Feature\Api;

use App\Models\Workflow;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The surface that makes automation tenant-configurable (plan §5.5, §2.1).
 *
 * An engine nobody can configure is not the no-hardcoding rule — it is a
 * hardcoded engine. These test the things that make it safe to hand to a tenant.
 */
class WorkflowsApiTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_catalogue_comes_from_the_module_registry(): void
    {
        $response = $this->as()->getJson('/api/v1/workflows/catalogue')->assertOk();

        $modules = collect($response->json('data.modules'))->keyBy('key');

        // A module added next year appears here the moment it declares itself.
        // That is the §5.5 standing rule doing its job — nothing is listed twice,
        // in code and in a catalogue that drifts.
        $this->assertTrue($modules->has('jobs'));
        $this->assertContains('job.status_changed', $modules['jobs']['triggers']);
        $this->assertNotEmpty($response->json('data.operators'));
    }

    public function test_a_new_workflow_starts_switched_off(): void
    {
        $response = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Close completed jobs',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'notify', 'message' => 'Job {{ title }} is done']],
        ])->assertCreated();

        // A rule that starts firing the instant it is saved fires before anyone
        // has read it back.
        $this->assertFalse($response->json('data.is_active'));
        $this->assertSame(1, $response->json('data.version'));
    }

    public function test_a_trigger_nothing_emits_is_refused_at_save_time(): void
    {
        $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Never fires',
            'trigger_key' => 'job.levitated',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertStatus(422)
          ->assertJsonPath('errors.trigger_key.0',
              "Nothing in this system emits 'job.levitated'. Pick one from the catalogue "
              .'— a rule listening for something that never happens looks configured and '
              .'simply never runs.');
    }

    public function test_an_internal_webhook_is_refused_while_someone_is_looking_at_the_form(): void
    {
        $response = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Exfiltrate',
            'trigger_key' => 'job.created',
            'actions' => [['type' => 'webhook', 'url' => 'https://169.254.169.254/latest/meta-data/']],
        ])->assertStatus(422);

        // Checked at save, not only when it runs hours later in a queue worker
        // where only the run log would mention it.
        // The key is the literal string 'actions.0.url', so dot traversal walks past it.
        $errors = $response->json('errors');
        $this->assertStringContainsString('internal address', $errors['actions.0.url'][0]);
    }

    public function test_editing_writes_a_new_version_and_leaves_the_old_one_alone(): void
    {
        $id = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'first']],
        ])->assertCreated()->json('data.id');

        $updated = $this->as()->patchJson("/api/v1/workflows/{$id}", [
            'actions' => [['type' => 'notify', 'message' => 'second']],
        ])->assertOk();

        $this->assertSame(2, $updated->json('data.version'));
        $this->assertCount(2, $updated->json('data.versions'));
        $this->assertSame('second', $updated->json('data.actions.0.message'));
    }

    public function test_an_earlier_version_can_be_reactivated(): void
    {
        $created = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'first']],
        ])->assertCreated();

        $id = $created->json('data.id');
        $firstVersion = $created->json('data.versions.0.id');

        $this->as()->patchJson("/api/v1/workflows/{$id}", [
            'actions' => [['type' => 'notify', 'message' => 'second']],
        ])->assertOk();

        // Rollback is the whole reason versions are immutable: pointing back at
        // an older definition is the recovery path when a new rule is wrong.
        $rolled = $this->as()
            ->postJson("/api/v1/workflows/{$id}/activate/{$firstVersion}")
            ->assertOk();

        $this->assertSame('first', $rolled->json('data.actions.0.message'));
        $this->assertSame(1, $rolled->json('data.version'));
    }

    public function test_the_trigger_cannot_be_changed_after_creation(): void
    {
        $id = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        // Changing it makes it a different rule, and every run recorded so far
        // would describe something it no longer does.
        $this->as()->patchJson("/api/v1/workflows/{$id}", [
            'trigger_key' => 'job.deleted',
        ])->assertStatus(422);
    }

    public function test_testing_a_rule_with_nothing_to_test_against_says_so(): void
    {
        $id = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Rule', 'trigger_key' => 'customer.owner_reassigned',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        // Better than an empty result that looks like "it did not match".
        $this->as()->postJson("/api/v1/workflows/{$id}/test")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_event_to_test');
    }

    public function test_re_enabling_clears_the_auto_disable(): void
    {
        $id = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        Workflow::withoutGlobalScopes()->whereKey($id)->update([
            'disabled_at' => now(), 'disabled_reason' => 'It kept failing.',
            'consecutive_failures' => 5,
        ]);

        $response = $this->as()->patchJson("/api/v1/workflows/{$id}", ['is_active' => true])->assertOk();

        // Switching it back on is a deliberate act that clears the failure count
        // — otherwise it would trip again on the next single failure.
        $this->assertTrue($response->json('data.is_live'));
        $this->assertNull($response->json('data.disabled_at'));
    }

    public function test_another_organization_cannot_see_or_edit_the_rules(): void
    {
        $id = $this->as()->postJson('/api/v1/workflows', [
            'name' => 'Alpha rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        app(ProvisionOrganization::class)(
            organizationName: 'Bravo',
            administrator: ['name' => 'B', 'email' => 'b@bravo.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'b@bravo.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/workflows')->assertOk()->assertJsonCount(0, 'data');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/workflows/{$id}")->assertNotFound();
    }
}
