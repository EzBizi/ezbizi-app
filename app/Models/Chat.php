<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One conversation in Chat: two people (direct) or a named group. */
class Chat extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'type', 'name', 'description', 'only_admins_send',
        'created_by', 'direct_key', 'job_id', 'last_message_at',
    ];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['only_admins_send' => 'boolean', 'last_message_at' => 'datetime'];
    }

    /**
     * A conversation nobody has written in for two years goes, with its
     * members and messages (cascade). Same window as the messages themselves;
     * every message touches updated_at.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(24, 'updated_at',
            'Chat conversations: who is in them and the group name. Deleted two '
            .'years after the last message, with everything in them.');
    }

    public static function directKey(string $a, string $b): string
    {
        $pair = [$a, $b];
        sort($pair);

        return implode(':', $pair);
    }

    /** The job this conversation is about, if any. */
    public function job(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
