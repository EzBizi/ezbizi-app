<?php

namespace App\Support\Auth;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API key is a Sanctum token with a name prefix, nothing more.
 *
 * It is a person's own token, so it carries exactly that person's tenant,
 * permissions and current-view rules — every check a signed-in session meets,
 * an AI holding the key meets too. What makes it a KEY rather than a session:
 * it never idles out (SlideIdleWindow skips it), it is not listed or revoked as
 * a session, and a read-only key refuses every write (EnforceApiKeyAccess).
 */
final class ApiKey
{
    public const PREFIX = 'api-key:';

    /** Abilities: '*' may write; 'read' may only GET. */
    public const READ = 'read';

    public static function is(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken && str_starts_with((string) $token->name, self::PREFIX);
    }

    public static function label(PersonalAccessToken $token): string
    {
        return substr($token->name, strlen(self::PREFIX));
    }
}
