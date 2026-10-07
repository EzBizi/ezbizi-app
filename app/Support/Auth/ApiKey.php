<?php

namespace App\Support\Auth;

use App\Models\User;
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

    /** Levels, weakest first. Stored as the ability beside the marker; full is '*'. */
    public const LEVELS = ['viewer', 'editor', 'full'];

    /** The `api_keys.*` permission that lets a role make a key of each level. */
    public const PERMISSION = ['viewer' => 'api_keys.view', 'editor' => 'api_keys.edit', 'full' => 'api_keys.delete'];

    private const ABILITY = ['viewer' => 'read', 'editor' => 'edit', 'full' => '*'];

    public static function abilities(string $level): array
    {
        return [self::MARKER, self::ABILITY[$level]];
    }

    public static function levelOf(PersonalAccessToken $token): string
    {
        return $token->can('*') ? 'full' : ($token->can('edit') ? 'editor' : 'viewer');
    }

    /** The strongest level this person's role allows right now, or null for none. */
    public static function grantedTo(User $user): ?string
    {
        foreach (array_reverse(self::LEVELS) as $level) {
            if ($user->hasPermission(self::PERMISSION[$level])) {
                return $level;
            }
        }

        return null;
    }

    /** What a key may do now: its own level, capped by what its owner's role allows today. */
    public static function effectiveLevel(PersonalAccessToken $token, User $user): ?string
    {
        $granted = self::grantedTo($user);

        return $granted === null ? null : self::LEVELS[min(
            array_search(self::levelOf($token), self::LEVELS, true),
            array_search($granted, self::LEVELS, true),
        )];
    }

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
