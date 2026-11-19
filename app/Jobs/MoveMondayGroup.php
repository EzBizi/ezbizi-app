<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayToken;
use App\Support\Tasks\Monday\MondayWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A group moved here moves on Monday too (Jose 11-19, full parity on group
 * order). Monday's update_group takes relative_position_after (or _before) with
 * the group to sit below (or above): placed after the nearest group above it
 * here that Monday has, else before the nearest one below.
 */
final class MoveMondayGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public readonly string $boardId, public readonly string $groupId)
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
            $groups = TaskGroup::query()->where('board_id', $board->id)->orderBy('position')->get()->values();
            $at = $groups->search(fn (TaskGroup $g) => $g->id === $this->groupId);
            $me = $at === false ? null : $groups[$at];
            if ($me === null || ! str_starts_with((string) $me->external_ref, 'monday:')) {
                return;
            }
            $onMonday = fn (TaskGroup $g) => str_starts_with((string) $g->external_ref, 'monday:');
            $above = $groups->take($at)->reverse()->first($onMonday);
            $below = $groups->slice($at + 1)->first($onMonday);
            [$attribute, $other] = $above !== null ? ['relative_position_after', $above] : ['relative_position_before', $below];
            if ($other === null) {
                return; // the only group Monday has
            }
            $connection = SocialConnection::query()->where('provider', SocialProvider::Monday->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->first();
            if ($connection === null) {
                return;
            }
            (new MondayWriter(app(MondayToken::class)->for($connection)))->mutate(
                'mutation($b:ID!,$g:String!,$v:String!){ update_group(board_id:$b, group_id:$g, group_attribute:'.$attribute.', new_value:$v) { id } }',
                ['b' => substr($board->external_ref, 7), 'g' => substr($me->external_ref, 7), 'v' => substr($other->external_ref, 7)],
            );
        });
    }
}
