<?php

namespace Tests\Feature\Tasks;

use App\Models\Franchise;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Suggestion 01a20914 (11-23): "I can not tag James in Ohio" from an NJ item. Monday tags anyone; the tag makes them a subscriber. */
class MentionJoinsBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_tagging_another_branchs_person_offers_them_and_puts_them_on_the_board(): void
    {
        $t = $this->provisionTenant('Alpha');
        [$board, $ohio] = $this->inTenant($t['org'], fn () => [
            TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first(),
            Franchise::query()->create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Ohio']),
        ]);
        $james = $this->userWithRole($t, 'Technician', ['tasks.view', 'tasks.edit'], 'james@example.test', $ohio);
        $boards = app(TaskBoards::class);
        $this->assertFalse($this->inTenant($t['org'], fn () => $boards->canView($james->fresh(), $board)), 'precondition: James cannot see this branch');

        $api = $this->signedInAs($t['admin']);
        $task = $api->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'No heat'])->assertCreated()->json('data.id');
        $this->assertContains($james->id, array_column($api->getJson("/api/v1/mentionable?type=task&id=$task")->assertOk()->json('data'), 'id'));

        $api->postJson("/api/v1/tasks/$task/updates", ['body' => '@James can you take it?', 'mentions' => [$james->id]])->assertCreated();
        $this->assertTrue($this->inTenant($t['org'], fn () => $boards->canView($james->fresh(), $board)), 'tagged = on the board');
    }

    public function test_a_private_board_neither_lists_nor_adds_a_non_member(): void
    {
        $t = $this->provisionTenant('Alpha');
        $james = $this->userWithRole($t, 'Technician', ['tasks.view', 'tasks.edit'], 'james@example.test');
        $api = $this->signedInAs($t['admin']);
        $board = $this->inTenant($t['org'], fn () => app(TaskBoards::class)->createCustom($t['admin']->fresh(), 'Company Files', TaskBoard::PRIVATE, $t['company'], null));
        $task = $api->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Card'])->assertCreated()->json('data.id');

        $this->assertNotContains($james->id, array_column($api->getJson("/api/v1/mentionable?type=task&id=$task")->assertOk()->json('data'), 'id'));
        $api->postJson("/api/v1/tasks/$task/updates", ['body' => '@James', 'mentions' => [$james->id]])->assertUnprocessable();
        $this->assertFalse($this->inTenant($t['org'], fn () => $board->members()->where('user_id', $james->id)->exists()), 'not pulled in');
    }
}
