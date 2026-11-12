<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use App\Models\PhoneRingGroup;
use App\Models\PhoneVoicemailBox;
use App\Models\Role;

/**
 * A connected account answers calls without anybody setting it up (Jose,
 * 2026-11-12: "doing a connection should take care of the whole process").
 * Fills only what is empty: an "Everyone" ring group of the roles that use
 * the phone, and a voicemail box behind it. Never replaces a choice.
 */
class ConnectionDefaults
{
    /** @return list<string> what was created */
    public static function ensure(PhoneConnection $connection): array
    {
        $made = [];
        $scope = ['scope_type' => $connection->scope_type, 'scope_id' => $connection->scope_id];

        if ($connection->default_voicemail_box_id === null) {
            $box = PhoneVoicemailBox::query()->create($scope + [
                'organization_id' => $connection->organization_id,
                'connection_id' => $connection->id,
                'name' => 'Main voicemail',
                'owner_type' => PhoneVoicemailBox::OWNER_COMPANY,
            ]);
            $connection->default_voicemail_box_id = $box->id;
            $made[] = 'voicemail box';
        }

        if ($connection->default_ring_group_id === null) {
            $group = PhoneRingGroup::query()->create($scope + [
                'organization_id' => $connection->organization_id,
                'connection_id' => $connection->id,
                'name' => 'Everyone',
                'mode' => PhoneRingGroup::MODE_SIMULTANEOUS,
                'timeout_seconds' => 30,
                'member_user_ids' => [],
                'member_role_ids' => Role::query()->get()->filter(fn (Role $role) => $role->hasPermission('phone.view'))->pluck('id')->values()->all(),
                'overflow' => PhoneRingGroup::OVERFLOW_VOICEMAIL,
            ]);
            $connection->default_ring_group_id = $group->id;
            $made[] = 'ring group';
        }

        $connection->save();

        return $made;
    }
}
