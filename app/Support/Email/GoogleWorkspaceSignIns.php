<?php

namespace App\Support\Email;

use App\Models\GoogleWorkspaceSignIn;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issuing and burning the `state` that binds a Workspace-domain Google sign-in.
 *
 * Same shape as {@see GoogleOAuthSignIns}: the callback is unauthenticated, so
 * the row IS the identification. There is no mailbox — the domain is what is
 * being proved.
 */
final class GoogleWorkspaceSignIns
{
    public const MINUTES = 10;

    public const WAITING = 'waiting';

    public const CONNECTED = 'connected';

    public const REFUSED = 'refused';

    private const OUTCOME_MINUTES = 15;

    public function __construct(private readonly GoogleOAuth $google) {}

    /**
     * @return array{url: string, flow: string, expires_at: \Illuminate\Support\Carbon}
     *
     * @throws GoogleOAuthRefused
     */
    public function begin(
        User $user,
        Organization $organization,
        string $domain,
        string $scopeType = 'organization',
        ?string $scopeId = null,
    ): array {
        $state    = Str::random(64);
        $verifier = $this->google->newVerifier();
        $url      = $this->google->workspaceAuthorizeUrl($state, $verifier, $domain);
        $scopeId  ??= $organization->getKey();

        $this->clearExpired();

        $row = new GoogleWorkspaceSignIn;

        $row->forceFill([
            'organization_id' => $organization->getKey(),
            'user_id'         => $user->getKey(),
            'domain'          => strtolower($domain),
            'state_hash'      => hash('sha256', $state),
            'code_verifier'   => $verifier,
            'expires_at'      => now()->addMinutes(self::MINUTES),
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId,
        ])->save();

        return [
            'url'        => $url,
            'flow'       => (string) $row->getKey(),
            'expires_at' => $row->expires_at,
        ];
    }

    public function record(GoogleWorkspaceSignIn $signIn, string $state, string $message): void
    {
        Cache::put($this->outcomeKey((string) $signIn->getKey()), [
            'organization_id' => (string) $signIn->organization_id,
            'state'           => $state,
            'message'         => $message,
        ], now()->addMinutes(self::OUTCOME_MINUTES));
    }

    /**
     * @return array{state: string, message: ?string}
     */
    public function outcome(Organization $organization, ?string $flow): array
    {
        if ($flow !== null && $flow !== '') {
            $recorded = Cache::get($this->outcomeKey($flow));

            if (! is_array($recorded)
                || ($recorded['organization_id'] ?? null) !== (string) $organization->getKey()) {
                return ['state' => self::WAITING, 'message' => null];
            }

            return [
                'state'   => (string) $recorded['state'],
                'message' => (string) $recorded['message'],
            ];
        }

        return ['state' => self::WAITING, 'message' => null];
    }

    /**
     * Burn a Workspace sign-in if this state names one; otherwise leave it.
     *
     * Null means this callback is the per-inbox Gmail flow (or nothing). Thrown
     * refusal means it WAS a Workspace sign-in and is already spent.
     *
     * @throws GoogleOAuthRefused
     */
    public function completeIfPresent(string $state): ?GoogleWorkspaceSignIn
    {
        $row = GoogleWorkspaceSignIn::where('state_hash', hash('sha256', $state))->first();

        if ($row === null) {
            return null;
        }

        if (GoogleWorkspaceSignIn::whereKey($row->getKey())->delete() !== 1) {
            throw GoogleOAuthRefused::noOpenSignIn();
        }

        if ($row->hasExpired()) {
            throw GoogleOAuthRefused::noOpenSignIn();
        }

        return $row;
    }

    private function clearExpired(): void
    {
        GoogleWorkspaceSignIn::where('expires_at', '<', now())->delete();
    }

    private function outcomeKey(string $flow): string
    {
        return 'google-workspace-sign-in:'.$flow;
    }
}
