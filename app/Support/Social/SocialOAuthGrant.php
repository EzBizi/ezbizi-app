<?php

namespace App\Support\Social;

/** What a network handed back from the token exchange. */
final readonly class SocialOAuthGrant
{
    /**
     * @param  string[]  $scopes
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken = null,
        public array $scopes = [],
        public ?int $expiresIn = null,
        public array $extra = [],
    ) {}

    /**
     * The scopes a token response says were granted, however the network
     * spells them: Google and X space-separate, Pinterest and TikTok use
     * commas, Instagram Login may send a list. Splitting on whitespace alone
     * read Pinterest's "boards:read,pins:write" as one unknown scope.
     *
     * @return string[]
     */
    public static function parseScopes(mixed $raw): array
    {
        $parts = is_array($raw)
            ? $raw
            : preg_split('/[\s,]+/', trim(is_scalar($raw) ? (string) $raw : ''), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_filter(array_map(
            fn ($s) => is_scalar($s) ? trim((string) $s) : '',
            (array) $parts,
        ))));
    }
}
