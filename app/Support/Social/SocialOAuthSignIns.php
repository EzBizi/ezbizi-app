<?php

namespace App\Support\Social;

use App\Models\SocialSignIn;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class SocialOAuthSignIns
{
    public const MINUTES = 10;
    public const WAITING = 'waiting';
    public const CONNECTED = 'connected';
    public const REFUSED = 'refused';

    private const OUTCOME_MINUTES = 15;

    /**
     * @return array{url: string, flow: string, expires_at: \Illuminate\Support\Carbon}
     *
     * @throws SocialOAuthRefused
     */
    public function begin(User $user, SocialOAuth $oauth, string $scopeType, string $scopeId): array
    {
        $state    = Str::random(64);
        $verifier = $oauth->usesPkce() ? Pkce::verifier() : null;
        $url      = $oauth->authorizeUrl($state, $verifier);

        $this->clearExpired();

        $row = new SocialSignIn;
        $row->forceFill([
            'organization_id' => $user->organization_id,
            'user_id'         => $user->getKey(),
            'provider'        => $oauth->provider()->value,
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId,
            'state_hash'      => $this->fingerprint($state),
            'code_verifier'   => $verifier,
            'expires_at'      => now()->addMinutes(self::MINUTES),
        ])->save();

        return ['url' => $url, 'flow' => (string) $row->getKey(), 'expires_at' => $row->expires_at];
    }

    public function record(SocialSignIn $signIn, string $state, string $message): void
    {
        Cache::put($this->outcomeKey((string) $signIn->getKey()), [
            'user_id' => (string) $signIn->user_id,
            'state'   => $state,
            'message' => $message,
        ], now()->addMinutes(self::OUTCOME_MINUTES));
    }

    /**
     * @return array{state: string, message: ?string}
     */
    public function outcome(User $user, ?string $flow): array
    {
        if ($flow !== null && $flow !== '') {
            $recorded = Cache::get($this->outcomeKey($flow));

            if (! is_array($recorded) || ($recorded['user_id'] ?? null) !== (string) $user->getKey()) {
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
     * @throws SocialOAuthRefused
     */
    public function complete(string $state, string $network): SocialSignIn
    {
        $row = SocialSignIn::where('state_hash', $this->fingerprint($state))->first();

        if ($row === null || ! $this->burn($row)) {
            throw SocialOAuthRefused::noOpenSignIn($network);
        }

        if ($row->hasExpired()) {
            throw SocialOAuthRefused::noOpenSignIn($network);
        }

        return $row;
    }

    private function burn(SocialSignIn $row): bool
    {
        return SocialSignIn::whereKey($row->getKey())->delete() === 1;
    }

    private function clearExpired(): void
    {
        SocialSignIn::where('expires_at', '<', now())->delete();
    }

    private function fingerprint(string $state): string
    {
        return hash('sha256', $state);
    }

    private function outcomeKey(string $flow): string
    {
        return 'social.sign-in-outcome.'.$flow;
    }
}
