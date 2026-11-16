<?php

namespace Tests\Feature\Tasks;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The avatar hover card answers only for people the board can name. */
class TaskBoardPersonCardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_card_answers_for_board_people_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $owner = $this->userWithRole($tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards']);
        $outsider = $this->userWithRole($tenant, 'Outsider', ['tasks.view']);
        $id = $this->signedInAs($owner)->postJson('/api/v1/task-boards', [
            'name' => 'Secret', 'visibility' => 'private', 'franchise_id' => $tenant['franchise']->id,
        ])->assertCreated()->json('data.id');

        $this->signedInAs($owner)->getJson("/api/v1/task-boards/{$id}/people/{$owner->id}")->assertOk()
            ->assertJsonPath('data.name', 'Owner')
            ->assertJsonPath('data.role_name', 'Owner')
            ->assertJsonStructure(['data' => ['timezone', 'local_time', 'presence', 'email', 'phone', 'has_avatar']]);

        // Somebody the private board cannot name is not answered.
        $this->signedInAs($owner)->getJson("/api/v1/task-boards/{$id}/people/{$outsider->id}")->assertNotFound();
        // Nor can somebody who cannot see the board ask.
        $this->signedInAs($outsider)->getJson("/api/v1/task-boards/{$id}/people/{$owner->id}")->assertNotFound();
    }
}
