<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A WhatsApp group reports are delivered to (App\Support\Social\WhatsAppGroups).
 */
class WhatsAppGroup extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'whatsapp_groups';

    protected $fillable = ['organization_id', 'phone_number_id', 'purpose', 'subject', 'remote_id', 'invite_link'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'whatsapp_group_members', 'whatsapp_group_id', 'user_id')
            ->withPivot(['id', 'organization_id'])
            ->withTimestamps();
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Which WhatsApp group each report goes to and who is in it. Configuration, kept '
            .'until the business deletes the group.'
        );
    }
}
