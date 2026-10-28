<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One inbox filter, personal or shared over a place. See the create_email_filters migration. */
class EmailFilter extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** Matching mail goes to the Notifications tab. */
    public const ACTION_NOTIFICATIONS = 'notifications';

    /** Matching mail is left out of the inbox list in EzBizi. */
    public const ACTION_HIDE = 'hide';

    public const ACTIONS = [self::ACTION_NOTIFICATIONS, self::ACTION_HIDE];

    protected $fillable = ['user_id', 'scope_type', 'scope_id', 'name', 'from_contains', 'subject_contains', 'action', 'created_by'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An inbox filter somebody made: a name, the sender or subject text it matches, and what it '
            .'does. Held until it is deleted; a personal filter goes with its person.'
        );
    }

    public function isShared(): bool
    {
        return $this->user_id === null;
    }

    /** Does this mail match? Case-insensitive "contains" on each field set. */
    public function matches(string $fromName, string $fromAddress, string $subject): bool
    {
        $from = mb_strtolower($fromName.' '.$fromAddress);

        return ($this->from_contains === null || str_contains($from, mb_strtolower($this->from_contains)))
            && ($this->subject_contains === null || str_contains(mb_strtolower($subject), mb_strtolower($this->subject_contains)));
    }
}
