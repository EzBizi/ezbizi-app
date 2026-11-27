<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Customer tags (Jose, 2026-11-03: newsletters and automations "by tag"). */
class CustomerTagsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_tags_are_saved_once_per_spelling_and_filter_the_list(): void
    {
        $t = $this->provisionTenant('Alpha', 'a@alpha.test');
        $as = $this->signedInAs($t['admin']);

        $ann = $as->postJson('/api/v1/customers', [
            'owning_franchise_id' => $t['franchise']->id, 'name' => 'Ann',
            'tags' => [' VIP ', 'vip', 'Gas fireplace', ''],
        ])->assertCreated()->json('data');
        $as->postJson('/api/v1/customers', ['owning_franchise_id' => $t['franchise']->id, 'name' => 'Bob'])->assertCreated();

        $this->assertSame(['VIP', 'Gas fireplace'], $ann['tags']);
        $this->assertSame(['Ann'], array_column($as->getJson('/api/v1/customers?tag=vip')->assertOk()->json('data'), 'name'));

        // Removing is saving the list without it; an empty list clears.
        $as->patchJson("/api/v1/customers/{$ann['id']}", ['tags' => []])->assertOk();
        $this->assertSame([], $as->getJson('/api/v1/customers?tag=vip')->json('data'));
        $this->assertSame([], $as->getJson("/api/v1/customers/{$ann['id']}")->json('data.tags'));
    }

    public function test_a_tag_change_raises_customer_tagged_and_other_edits_do_not(): void
    {
        $t = $this->provisionTenant('Alpha', 'a@alpha.test');
        $as = $this->signedInAs($t['admin']);
        $id = $as->postJson('/api/v1/customers', ['owning_franchise_id' => $t['franchise']->id, 'name' => 'Ann'])
            ->assertCreated()->json('data.id');
        $tagged = fn () => \App\Models\AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'customer.tagged')->orderBy('occurred_at')->get();

        $as->patchJson("/api/v1/customers/{$id}", ['name' => 'Ann B'])->assertOk();
        $this->assertCount(0, $tagged(), 'a name edit is not a tag change');

        $as->patchJson("/api/v1/customers/{$id}", ['tags' => ['VIP']])->assertOk();
        $as->patchJson("/api/v1/customers/{$id}", ['tags' => ['vip']])->assertOk(); // same tag, other case: still a change of spelling

        $events = $tagged();
        $this->assertSame(['tags' => []], $events[0]->payload['before']);
        $this->assertSame(['tags' => ['VIP']], $events[0]->payload['after']);
    }

    public function test_the_tags_in_use_come_most_used_first_one_per_spelling(): void
    {
        $t = $this->provisionTenant('Alpha', 'a@alpha.test');
        $as = $this->signedInAs($t['admin']);
        foreach ([['VIP', 'Gas'], ['vip'], ['Gas', 'vip']] as $i => $tags) {
            $as->postJson('/api/v1/customers', ['owning_franchise_id' => $t['franchise']->id, 'name' => "C{$i}", 'tags' => $tags])->assertCreated();
        }

        $this->assertSame(
            [['tag' => 'VIP', 'customers' => 3], ['tag' => 'Gas', 'customers' => 2]],
            $as->getJson('/api/v1/customers/tags')->assertOk()->json('data'),
        );
    }
}
