<?php

namespace App\Support\Chat;

use App\Models\Chat;
use App\Models\ChatMember;
use App\Models\Company;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Automatic groups (Jose, 11-27): per top-level company a "Dispatchers", a
 * "Technicians" and a "Leadership" group. Which roles feed each one is a
 * setting (Communication › Chat groups), so an administrator changes it as
 * roles are split or renamed. An active user with one of the roles and reach in
 * the company (or any of its Sub Companies) is in; deactivation, a role change
 * out or losing the reach takes them out. Quiet: no pushes, no lines.
 */
final class ChatAutoGroups
{
    /** Kind => the setting holding its role ids, and the group's name. */
    public const KINDS = [
        'dispatchers' => ['setting' => 'phone.chat_groups_dispatchers', 'name' => 'Dispatchers'],
        'technicians' => ['setting' => 'phone.chat_groups_technicians', 'name' => 'Technicians'],
        'leadership' => ['setting' => 'phone.chat_groups_leadership', 'name' => 'Leadership'],
    ];

    public function __construct(private readonly TenantContext $tenant, private readonly ChatPulse $pulse) {}

    /** @return string[] the kinds this role feeds, in this organization */
    public static function kindsFor(?string $roleId, string $organizationId): array
    {
        if ($roleId === null) {
            return [];
        }
        // Through the resolver (the settings guard's reader), then its memo is
        // emptied again: this runs from a User save in the middle of other
        // requests, and a memo filled here left a later settings write in the
        // same request reading a stale copy (0662 box gate, OrganizationCanvasTest).
        $resolver = app(SettingsResolver::class);
        $kinds = [];
        try {
            foreach (self::KINDS as $kind => $def) {
                if (in_array($roleId, (array) SettingsResolver::forOrganization($def['setting'], $organizationId), true)) {
                    $kinds[] = $kind;
                }
            }
        } finally {
            $resolver->flush();
        }

        return $kinds;
    }

    /** Everyone in the organization again: after its role lists changed. */
    public function syncOrganization(string $organizationId): void
    {
        User::query()->withoutGlobalScopes()->where('organization_id', $organizationId)
            ->each(fn (User $u) => $this->sync($u));
    }

    public function sync(User $user): void
    {
        $org = $user->organization()->withoutGlobalScopes()->first();
        if ($org === null) {
            return;
        }
        $this->tenant->run($org, function () use ($user) {
            $user = User::query()->with('role', 'assignments')->find($user->getKey());
            if ($user === null) {
                return;
            }
            $kinds = $user->is_active ? self::kindsFor($user->role_id, $user->organization_id) : [];

            $want = []; // auto_key => [top-level company, kind]
            if ($kinds !== []) {
                $all = Company::query()->get()->keyBy('id');
                foreach ($user->reachableCompanies() as $company) {
                    // A Sub Company's people are in its top company's group.
                    $root = $company;
                    for ($i = 0; $i < 10 && isset($all[$root->parent_company_id]); $i++) {
                        $root = $all[$root->parent_company_id];
                    }
                    if ($root->parent_company_id === null && $root->is_active) {
                        foreach ($kinds as $kind) {
                            $want["{$kind}:{$root->id}"] = [$root, $kind];
                        }
                    }
                }
            }

            // Out of every automatic group not wanted any more.
            $rows = ChatMember::query()->where('user_id', $user->id)->whereNull('left_at')
                ->whereIn('chat_id', Chat::query()->whereNotNull('auto_key')->select('id'))
                ->with('chat:id,auto_key')->get();
            foreach ($rows as $row) {
                if (! isset($want[$row->chat?->auto_key])) {
                    $row->forceFill(['left_at' => now(), 'is_admin' => false])->save();
                    $this->pulse->bump($row->chat, only: [$user->id]);
                }
            }

            // Into every one wanted, the group made on first need.
            foreach ($want as $key => [$company, $kind]) {
                $chat = Chat::query()->firstOrCreate(
                    ['auto_key' => $key],
                    ['type' => 'group', 'name' => $company->name.' '.self::KINDS[$kind]['name']],
                );
                $member = ChatMember::query()->firstOrNew(['chat_id' => $chat->id, 'user_id' => $user->id]);
                if ($member->exists && $member->left_at === null) {
                    continue;
                }
                $member->forceFill(['left_at' => null, 'is_admin' => false, 'read_at' => $chat->last_message_at ?? now()])->save();
                $this->pulse->bump($chat, only: [$user->id]);
            }
        });
    }
}
