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
}
