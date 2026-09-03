<?php

namespace Tests\Feature;

use App\Models\Suggestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A private channel from one person to EzBizi.
 *
 * The tenant scope is NOT the boundary here and the tests say so: a colleague
 * in the same organization is inside the tenant scope and must still see
 * nothing. That is the difference between this module and every other one.
 */
class SuggestionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_suggestion_comes_back_to_its_author(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Let the board remember its filter.'])
            ->assertCreated()
            ->assertJsonPath('data.status', Suggestion::STATUS_NEW);

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Let the board remember its filter.');
    }

    public function test_a_colleague_in_the_same_organization_sees_nothing(): void
    {
        $t = $this->provisionTenant();
        $colleague = $this->userWithRole($t, 'Manager', []);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Mine alone.'])
            ->assertCreated();

        $this->signedInAs($colleague)
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_another_organization_sees_nothing(): void
    {
        $mine = $this->provisionTenant('Alpha');
        $theirs = $this->provisionTenant('Beta', 'beta@example.test');

        $this->signedInAs($mine['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Alpha only.'])
            ->assertCreated();

        $this->signedInAs($theirs['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_empty_suggestion_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => '   '])
            ->assertStatus(422);
    }

    public function test_a_suggestion_over_the_limit_is_refused(): void
    {
        $t = $this->provisionTenant();

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => str_repeat('a', 2001)])
            ->assertStatus(422);
    }

    /**
     * No permission key exists for this, and that is the point.
     *
     * A user holding NO permissions at all still reaches it. If this test ever
     * fails, somebody has registered the module — see Suggestion's docblock.
     */
    public function test_a_user_with_no_permissions_at_all_can_still_send_one(): void
    {
        $t = $this->provisionTenant();
        $nobody = $this->userWithRole($t, 'No permissions', []);

        $this->signedInAs($nobody)
            ->postJson('/api/v1/suggestions', ['body' => 'I hold nothing and may still speak.'])
            ->assertCreated();
    }

    public function test_an_author_can_correct_one_nobody_has_read(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Frist draft.'])
            ->json('data.id');

        $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/suggestions/{$id}", ['body' => 'First draft.'])
            ->assertOk()
            ->assertJsonPath('data.body', 'First draft.');
    }

    public function test_an_author_can_withdraw_one_nobody_has_read(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Never mind.'])
            ->json('data.id');

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/suggestions/{$id}")
            ->assertNoContent();

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/suggestions')
            ->assertJsonCount(0, 'data');
    }

    /**
     * The rule that matters: once somebody is reading it, the text is frozen.
     *
     * A suggestion rewritten under a reviewer produces a decision about words
     * that no longer exist, and a delete after a denial erases the reason.
     */
    public function test_editing_stops_once_it_is_in_review(): void
    {
        $t = $this->provisionTenant();

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Under a reviewer.'])
            ->json('data.id');

        $this->inTenant($t['org'], function () use ($id) {
            $row = Suggestion::query()->findOrFail($id);
            $row->status = Suggestion::STATUS_REVIEWING;
            $row->save();
        });

        $this->signedInAs($t['admin'])
            ->patchJson("/api/v1/suggestions/{$id}", ['body' => 'Changed my mind.'])
            ->assertStatus(422);

        $this->signedInAs($t['admin'])
            ->deleteJson("/api/v1/suggestions/{$id}")
            ->assertStatus(422);
    }

    public function test_a_colleague_cannot_edit_or_delete_it(): void
    {
        $t = $this->provisionTenant();
        $colleague = $this->userWithRole($t, 'Manager', []);

        $id = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/suggestions', ['body' => 'Mine alone.'])
            ->json('data.id');

        $this->signedInAs($colleague)
            ->patchJson("/api/v1/suggestions/{$id}", ['body' => 'Not yours.'])
            ->assertNotFound();

        $this->signedInAs($colleague)
            ->deleteJson("/api/v1/suggestions/{$id}")
            ->assertNotFound();

        $this->assertDatabaseHas('suggestions', ['id' => $id, 'body' => 'Mine alone.']);
    }
}
