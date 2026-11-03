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
}
