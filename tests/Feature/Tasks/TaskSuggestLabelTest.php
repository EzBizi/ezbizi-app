<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Support\Ai\AiProvider;
use App\Support\Ai\GrokCliProvider;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Monday's "Auto-assign labels": the AI suggests, the task is not changed. */
class TaskSuggestLabelTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public ?string $answer = null;

    public string $prompt = '';

    protected function setUp(): void
    {
        parent::setUp();
        $test = $this;
        $this->app->instance(GrokCliProvider::class, new class($test) implements AiProvider
        {
            public function __construct(private readonly TaskSuggestLabelTest $test) {}

            public function name(): string
            {
                return 'fake';
            }

            public function configured(): bool
            {
                return true;
            }

            public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array
            {
                $this->test->prompt = $prompts[0];

                return [['id' => $this->test->answer]];
            }
        });
    }

    public function test_it_suggests_a_status_of_the_board_and_ignores_an_invented_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$board, $task, $status] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $board = TaskBoard::query()->where('kind', 'franchise')->where('franchise_id', $tenant['franchise']->id)->firstOrFail();
            $task = app(TaskWriter::class)->create($board, ['title' => 'Chimney cap stuck'], null);

            return [$board, $task, $board->statuses()->first()];
        });

        $this->answer = (string) $status->id;
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$task->id}/suggest-label")
            ->assertOk()->assertJsonPath('data.id', (string) $status->id);
        $this->assertStringContainsString('Chimney cap stuck', $this->prompt);

        $this->answer = 'not-a-label';
        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$task->id}/suggest-label")
            ->assertOk()->assertJsonPath('data.id', null);
    }
}
