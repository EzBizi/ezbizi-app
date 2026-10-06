<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Somebody who gets a WhatsApp group's reports. */
class WhatsAppGroupMember extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_group_members';

    protected $fillable = ['organization_id', 'whatsapp_group_id', 'user_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Who is in a WhatsApp report group. Goes with the group, and with the person.'
        );
    }
}
