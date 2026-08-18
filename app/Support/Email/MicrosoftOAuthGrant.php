<?php

namespace App\Support\Email;

/**
 * What Microsoft handed back when the authorization code was exchanged.
 *
 * Same split as {@see GoogleOAuthGrant}: the refresh token is lasting permission
 * and is stored; the access token lives about an hour and is not.
 */
final class MicrosoftOAuthGrant
{
    /**
     * @param  string[]  $scopes
     */
    public function __construct(
        public readonly string $refreshToken,
        public readonly string $accessToken,
        public readonly array $scopes,
    ) {}

    public function covers(string ...$required): bool
    {
        $granted = array_map(fn (string $scope) => $this->shortName($scope), $this->scopes);

        foreach ($required as $scope) {
            if (! in_array($this->shortName($scope), $granted, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Graph sometimes returns `https://graph.microsoft.com/Mail.Read` and
     * sometimes the short name. Compared as the last path segment so a consent
     * that granted the right thing is not refused for spelling.
     */
    private function shortName(string $scope): string
    {
        $scope = trim($scope);

        return str_contains($scope, '/') ? basename($scope) : $scope;
    }
}
