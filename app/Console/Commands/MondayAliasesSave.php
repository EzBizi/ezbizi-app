<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Monday off (11-26): the name each person had on Monday ("Chris" for Kristopher) is kept on the user, from the user
 * list saved the night before (/var/backups/ezbizi/monday-users-2026-11-25.json), so people search keeps finding them.
 */
class MondayAliasesSave extends Command
{
    protected $signature = 'ezbizi:monday-aliases-save {file : the saved Monday user list (JSON: people_map, users)} {--org= : organization id}';

    protected $description = "Keep each person's Monday name for people search";

    public function handle(): int
    {
        $data = json_decode((string) file_get_contents($this->argument('file')), true) ?: [];
        $map = array_change_key_case((array) ($data['people_map'] ?? []));
        $byEmail = User::query()->withoutGlobalScopes()->where('organization_id', $this->option('org'))->get(['id', 'email', 'name'])
            ->keyBy(fn (User $u) => mb_strtolower((string) $u->email));
        $n = 0;
        foreach ((array) ($data['users'] ?? []) as $m) {
            $email = mb_strtolower((string) ($m['email'] ?? ''));
            $user = $byEmail[mb_strtolower((string) ($map[$email] ?? $email))] ?? null;
            $name = trim((string) ($m['name'] ?? ''));
            // Only a name that differs from theirs here is a second way to find them.
            if ($user === null || $name === '' || mb_strtolower($name) === mb_strtolower((string) $user->name)) {
                continue;
            }
            User::query()->withoutGlobalScopes()->whereKey($user->id)->update(['also_known_as' => mb_substr($name, 0, 120)]);
            $n++;
        }
        $this->line("aliases kept: $n");

        return self::SUCCESS;
    }
}
