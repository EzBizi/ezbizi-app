<?php

namespace App\Support\Social;

/**
 * One network's authorization-code flow.
 *
 * Start and status are the same shape for every OAuth network. The callback
 * path is each class's own CALLBACK_PATH constant, because each console
 * registers one redirect URI, character for character.
 */
interface SocialOAuth
{
    public function provider(): SocialProvider;

    /** Path segment in /social/{key}/start and the reserved callback. */
    public function routeKey(): string;

    /** @return string[] */
    public function scopes(): array;

    public function usesPkce(): bool;

    /** LinkedIn often has no refresh token; refusing that would block Connect. */
    public function requiresRefreshToken(): bool;

    public function notReadyBecause(): ?string;

    /**
     * @throws SocialOAuthRefused
     */
    public function authorizeUrl(string $state, ?string $verifier = null): string;

    /**
     * @throws SocialOAuthRefused
     */
    public function exchange(string $code, ?string $verifier = null): SocialOAuthGrant;

    /**
     * @return SocialAccount[]
     *
     * @throws SocialOAuthRefused
     */
    public function identify(SocialOAuthGrant $grant): array;
}
