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
 * A group renamed or recoloured here is renamed or recoloured on Monday (Jose 11-25: "import every single
 * setting from monday", both ways until Monday is off). Monday's update_group: title, color (its hex).
 */
final class PushMondayGroup implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /** @param  list<string>  $attributes  'title' and/or 'color' */
    public function __construct(public readonly string $boardId, public readonly string $groupId, public readonly array $attributes)
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
            $group = TaskGroup::query()->find($this->groupId);
            if ($group === null || ! str_starts_with((string) $group->external_ref, 'monday:')) {
                return;
            }
            $connection = SocialConnection::query()->where('provider', SocialProvider::Monday->value)
                ->where('status', SocialConnection::STATUS_CONNECTED)->first();
            if ($connection === null) {
                return;
            }
            $writer = new MondayWriter(app(MondayToken::class)->for($connection));
            foreach ($this->attributes as $attribute) {
                $value = $attribute === 'title' ? (string) $group->name : (string) $group->colour;
                if ($value === '') {
                    continue;
                }
                $writer->mutate(
                    'mutation($b:ID!,$g:String!,$v:String!){ update_group(board_id:$b, group_id:$g, group_attribute:'.$attribute.', new_value:$v) { id } }',
                    ['b' => substr($board->external_ref, 7), 'g' => substr($group->external_ref, 7), 'v' => $value],
                );
            }
        });
    }
}
