<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\TaskBoard;
use App\Support\Social\SocialProvider;
use App\Support\Tasks\Monday\MondayClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Desk 11-24 (Jose: "watch for any new stuff"): every active Monday board the
 * connection can see has a Task Board mirror. One without (DMV NEW, made by
 * Shoval and found by hand) is reported each hour until it is imported with
 * ezbizi:monday-import-boards; the report reaches the error log the desk reads.
 */
class MondayUnmirrored extends Command
{
    protected $signature = 'ezbizi:monday-unmirrored';

    protected $description = 'Report active Monday boards that have no Task Board mirror';

    public function handle(TenantContext $tenant): int
    {
        $missing = [];
        foreach (SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::Monday->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)->get() as $connection) {
            $api = MondayClient::for($connection);
            $there = [];
            for ($page = 1; ; $page++) {
                $boards = $api->query('query($p:Int!){ boards(limit:100, page:$p, state:active) { id name type } }', ['p' => $page])['boards'] ?? [];
                foreach ($boards as $b) {
                    // Subitem boards are part of their parent's mirror.
                    if (($b['type'] ?? 'board') === 'board') {
                        $there[(string) $b['id']] = (string) $b['name'];
                    }
                }
                if (count($boards) < 100) {
                    break;
                }
            }
            $here = $tenant->run(Organization::query()->withoutGlobalScopes()->findOrFail($connection->organization_id),
                fn () => TaskBoard::query()->withTrashed()->where('external_ref', 'like', 'monday:%')->pluck('external_ref')
                    ->map(fn ($r) => substr((string) $r, 7))->flip()->all());
            foreach (array_diff_key($there, $here) as $id => $name) {
                $missing[] = "{$name} ({$id})";
            }
        }

        if ($missing === []) {
            $this->line('Every active Monday board is mirrored.');

            return self::SUCCESS;
        }
        $this->error('Not mirrored: '.implode(', ', $missing));
        report(new \RuntimeException('Monday boards with no Task Board mirror: '.implode(', ', $missing)));

        return self::FAILURE;
    }
}
