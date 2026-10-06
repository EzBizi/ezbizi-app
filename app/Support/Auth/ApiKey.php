<?php

namespace App\Support\Auth;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API key is a Sanctum token carrying the MARKER ability, nothing more.
 *
 * It is a person's own token, so it carries exactly that person's tenant,
 * permissions and current-view rules — every check a signed-in session meets,
 * an AI holding the key meets too. What makes it a KEY rather than a session:
 * it never idles out (SlideIdleWindow skips it), it is not listed or revoked as
 * a session, and a read-only key refuses every write (EnforceApiKeyAccess).
 *
 * The marker is an ability, not a name prefix: sign-in names a session from the
 * client's `device_name`, so a name is something a client chooses — and a
 * session posing as a key would vanish from its owner's Sessions list.
 * Abilities are only ever set server-side.
 */
final class ApiKey
{
    public const MARKER = 'api-key';

    /** Abilities beside the marker: '*' may write; 'read' may only GET. */
    public const READ = 'read';

    public static function is(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && in_array(self::MARKER, (array) $token->abilities, true);
    }

    public static function only(Builder|Relation $query): Builder|Relation
    {
        return $query->where('abilities', 'like', '%"'.self::MARKER.'"%');
    }

    public static function except(Builder|Relation $query): Builder|Relation
    {
        return $query->where(fn ($q) => $q->whereNull('abilities')
            ->orWhere('abilities', 'not like', '%"'.self::MARKER.'"%'));
    }
}
