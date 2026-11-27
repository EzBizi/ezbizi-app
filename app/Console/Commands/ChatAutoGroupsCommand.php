<?php

namespace App\Console\Commands;

use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\User;
use App\Support\Chat\ChatAutoGroups;
use Illuminate\Console\Command;

/**
 * Puts today's staff into Chat's automatic Dispatchers / Technicians groups
 * once (Jose, 11-27). After this the groups follow the role by themselves
 * (ChatAutoGroups, hooked in AppServiceProvider). Quiet: no pushes, no lines.
 */
class ChatAutoGroupsCommand extends Command
{
    protected $signature = 'chat:auto-groups {--dry-run : Only say who would be placed}';

    protected $description = 'Fill Chat\'s automatic Dispatchers and Technicians groups from the current roles';

    public function handle(ChatAutoGroups $groups): int
    {
        $users = User::query()->withoutGlobalScopes()->where('is_active', true)->get()
            ->filter(fn (User $u) => ChatAutoGroups::kindsFor($u->role_id, $u->organization_id) !== []);

        $this->line(($this->option('dry-run') ? 'Would place ' : 'Placing ').$users->count().' people');
        if ($this->option('dry-run')) {
            foreach (array_keys(ChatAutoGroups::KINDS) as $kind) {
                $n = $users->filter(fn (User $u) => in_array($kind, ChatAutoGroups::kindsFor($u->role_id, $u->organization_id), true))->count();
                $this->line("  {$kind}: {$n}");
            }

            return self::SUCCESS;
        }

        // Everybody, not only the matches, so anyone already out of the roles leaves.
        User::query()->withoutGlobalScopes()->each(fn (User $user) => $groups->sync($user));

        $chats = Chat::query()->withoutGlobalScopes()->whereNotNull('auto_key')->get();
        foreach ($chats as $chat) {
            $n = ChatMember::query()->withoutGlobalScopes()->where('chat_id', $chat->id)->whereNull('left_at')->count();
            $this->line("  {$chat->name}: {$n}");
        }

        return self::SUCCESS;
    }
}
