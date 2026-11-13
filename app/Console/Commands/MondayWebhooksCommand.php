<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\TaskBoard;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayToken;
use App\Support\Tasks\Monday\MondayWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Register Monday's webhooks for every board imported from Monday (sync
 * design 2026-11-12: "webhooks for speed, a poll for truth"; Jose authorized
 * the writes 2026-11-13). Once per board and event: the ids are kept on the
 * connection (setup.monday.webhooks), so a re-run registers only what is new.
 * The endpoint verifies Monday's signature; the 10-minute poll stays.
 */
class MondayWebhooksCommand extends Command
{
    public const EVENTS = ['create_item', 'change_column_value', 'change_name', 'create_update', 'create_subitem',
        'change_subitem_column_value', 'item_moved_to_any_group', 'item_archived', 'item_deleted'];

    protected $signature = 'ezbizi:monday-webhooks {--connection=} {--dry-run : list what would be registered}';

    protected $description = 'Register Monday webhooks for the boards imported from Monday';

    public function handle(TenantContext $tenant, MondayToken $tokens): int
    {
        $url = route('integrations.monday.webhook');
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Monday->value)->where('status', SocialConnection::STATUS_CONNECTED)
            ->when($this->option('connection'), fn ($q, $id) => $q->whereKey($id))->get();

        foreach ($connections as $connection) {
            $org = Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id);
            $boards = $tenant->run($org, fn () => TaskBoard::query()->where('external_ref', 'like', 'monday:%')->pluck('external_ref')
                ->map(fn ($ref) => substr((string) $ref, 7))->filter(fn ($id) => ctype_digit($id))->values()->all());
            $writer = $this->option('dry-run') ? null : new MondayWriter($tokens->for($connection));
            $setup = (array) ($connection->setup ?? []);
            $have = (array) ($setup['monday']['webhooks'] ?? []);
            $made = 0;
            foreach ($boards as $board) {
                foreach (self::EVENTS as $event) {
                    if (isset($have[$board][$event])) {
                        continue;
                    }
                    if ($writer === null) {
                        $this->line("would register {$event} on board {$board}");

                        continue;
                    }
                    try {
                        $have[$board][$event] = $writer->createWebhook($board, $url, $event);
                        $made++;
                    } catch (\Throwable $e) {
                        $this->error("board {$board} {$event}: ".$e->getMessage());
                    }
                }
            }
            if ($writer !== null) {
                $tenant->run($org, function () use ($connection, $have) {
                    $connection->refresh();
                    $setup = (array) ($connection->setup ?? []);
                    $setup['monday']['webhooks'] = $have;
                    $connection->forceFill(['setup' => $setup])->save();
                });
            }
            $this->info($connection->display_name.': '.$made.' webhooks registered, '.count($boards).' boards.');
        }

        return self::SUCCESS;
    }
}
