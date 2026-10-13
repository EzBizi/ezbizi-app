<?php

namespace App\Support\Social;

/**
 * One page or profile the token can post as.
 *
 * Facebook Login can return several Pages from one consent; each becomes its
 * own social_connections row. A network that is one profile returns one.
 */
final readonly class SocialAccount
{
    public function __construct(
        public string $remoteId,
        public string $displayName,
        public ?string $handle = null,
        public ?string $accessToken = null,
        public ?string $refreshToken = null,
        /** Google Ads only: the manager this account is read THROUGH, when the grant reaches it that way. */
        public ?string $loginCustomerId = null,
        /** Google Ads only: a manager (MCC) account, as Google says. */
        public bool $isManager = false,
    ) {}
}
