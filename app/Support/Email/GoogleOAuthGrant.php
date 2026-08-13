<?php

namespace App\Support\Email;

/**
 * What Google handed back when the authorization code was exchanged.
 *
 * A small readonly object rather than the decoded array, so that the two tokens
 * cannot be confused for one another at a call site. They look identical — both
 * are opaque strings — and they are not remotely the same thing:
 *
 *   {@see $refreshToken} is the LASTING permission and the highest-value secret
 *   in the system. It is what gets stored, encrypted, on the mailbox.
 *
 *   {@see $accessToken} lives about an hour. It is used once here, to ask Google
 *   which account this actually is, and then thrown away. It is never persisted
 *   by this flow.
 *
 * Storing the wrong one produces a mailbox that works until lunchtime and then
 * reports that its sign-in was revoked, which is the most confusing possible
 * failure: the sentence would be true, the cause would be a typo, and nothing
 * would say so.
 */
final class GoogleOAuthGrant
{
    /**
     * @param  string[]  $scopes  what Google says was actually allowed — which
     *                            is not necessarily what was asked for, because
     *                            the consent screen lets somebody untick things
     */
    public function __construct(
        public readonly string $refreshToken,
        public readonly string $accessToken,
        public readonly array $scopes,
    ) {}

    /** Does this grant cover everything the module needs to be useful? */
    public function covers(string ...$required): bool
    {
        foreach ($required as $scope) {
            if (! in_array($scope, $this->scopes, true)) {
                return false;
            }
        }

        return true;
    }
}
