<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\TaskBoard;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tasks\Monday\MondayToken;
use App\Support\Tasks\Monday\MondayWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A group deleted here: its Monday twin is ARCHIVED once its items have moved
 * out (Jose 11-19). Never delete_group: Monday deletes the group's items with
 * it. archive_group archives the items inside too, so it only runs on a group
 * Monday shows empty; while items remain (the moves still in the outbox) it
 * waits and looks again.
 */
final class ArchiveMondayGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 12;

    public function __construct(public readonly string $boardId, public readonly string $mondayGroupId)
    {
        $this->onQueue('monday-push');
    }

    public function handle(TenantContext $tenant): void
    {
        $board = TaskBoard::query()->withoutGlobalScopes()->find($this->boardId);
        if ($board === null || ! str_starts_with((string) $board->external_ref, 'monday:')) {
            return;
        }
        $org = Organization::query()->withoutGlobalScopes()->findOrFail($board->organization_id);
        $tenant->run($org, function () use ($board) {
            $connection = SocialConnection::query()->where('provider', SocialProvider::Monday->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->first();
            if ($connection === null) {
                return;
            }
            $mondayBoard = substr($board->external_ref, 7);
            $group = MondayClient::for($connection)->query('query($b:[ID!],$g:[String]){ boards(ids:$b) { groups(ids:$g) { id archived items_page(limit:1) { items { id } } } } }',
                ['b' => [$mondayBoard], 'g' => [$this->mondayGroupId]])['boards'][0]['groups'][0] ?? null;
            if ($group === null || ($group['archived'] ?? false)) {
                return; // gone or already archived
            }
            if (($group['items_page']['items'] ?? []) !== []) {
                $this->release(300); // its items are still moving

                return;
            }
            (new MondayWriter(app(MondayToken::class)->for($connection)))
                ->mutate('mutation($b:ID!,$g:String!){ archive_group(board_id:$b, group_id:$g) { id } }', ['b' => $mondayBoard, 'g' => $this->mondayGroupId]);
        });
    }
}
