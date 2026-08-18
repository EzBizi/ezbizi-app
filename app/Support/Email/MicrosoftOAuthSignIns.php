<?php

namespace App\Support\Email;

use App\Models\Mailbox;
use App\Models\MicrosoftMailSignIn;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Issuing and burning the `state` that holds a Microsoft sign-in together.
 *
 * Same argument as {@see GoogleOAuthSignIns}: the callback is unauthenticated,
 * so `state` is the identification — organization, person, mailbox, PKCE.
 */
final class MicrosoftOAuthSignIns
{
    public const MINUTES = 10;

    public const WAITING = 'waiting';

    public const CONNECTED = 'connected';

    public const REFUSED = 'refused';

    private const OUTCOME_MINUTES = 15;

    public function __construct(private readonly MicrosoftOAuth $microsoft) {}

    /**
     * @return array{url: string, flow: string, expires_at: \Illuminate\Support\Carbon}
     *
     * @throws MicrosoftOAuthRefused
     */
    public function begin(User $user, Mailbox $mailbox): array
    {
        $state    = Str::random(64);
        $verifier = $this->microsoft->newVerifier();

        $url = $this->microsoft->authorizeUrl($state, $verifier, $mailbox->address);

        $this->clearExpired();

        $row = new MicrosoftMailSignIn;

        $row->forceFill([
            'organization_id' => $mailbox->organization_id,
            'user_id'         => $user->getKey(),
            'mailbox_id'      => $mailbox->getKey(),
            'state_hash'      => $this->fingerprint($state),
            'code_verifier'   => $verifier,
            'expires_at'      => now()->addMinutes(self::MINUTES),
        ])->save();

        return ['url' => $url, 'flow' => (string) $row->getKey(), 'expires_at' => $row->expires_at];
    }

    public function record(MicrosoftMailSignIn $signIn, string $state, string $message): void
    {
        Cache::put($this->outcomeKey((string) $signIn->getKey()), [
            'mailbox_id' => (string) $signIn->mailbox_id,
            'state'      => $state,
            'message'    => $message,
        ], now()->addMinutes(self::OUTCOME_MINUTES));
    }

    /**
     * @return array{state: string, message: ?string}
     */
    public function outcome(Mailbox $mailbox, ?string $flow): array
    {
        if ($flow !== null && $flow !== '') {
            $recorded = Cache::get($this->outcomeKey($flow));

            if (! is_array($recorded) || ($recorded['mailbox_id'] ?? null) !== (string) $mailbox->getKey()) {
                return ['state' => self::WAITING, 'message' => null];
            }

            return [
                'state'   => (string) $recorded['state'],
                'message' => (string) $recorded['message'],
            ];
        }

        return $mailbox->hasCredential()
            ? ['state' => self::CONNECTED, 'message' => null]
            : ['state' => self::WAITING, 'message' => null];
    }

    private function outcomeKey(string $flow): string
    {
        return 'email.microsoft.sign-in-outcome.'.$flow;
    }

    /**
     * @throws MicrosoftOAuthRefused
     */
    public function complete(string $state): MicrosoftMailSignIn
    {
        $row = MicrosoftMailSignIn::where('state_hash', $this->fingerprint($state))->first();

        if ($row === null || ! $this->burn($row)) {
            throw MicrosoftOAuthRefused::noOpenSignIn();
        }

        if ($row->hasExpired()) {
            throw MicrosoftOAuthRefused::noOpenSignIn();
        }

        return $row;
    }

    private function burn(MicrosoftMailSignIn $row): bool
    {
        return MicrosoftMailSignIn::whereKey($row->getKey())->delete() === 1;
    }

    private function clearExpired(): void
    {
        MicrosoftMailSignIn::where('expires_at', '<', now())->delete();
    }

    private function fingerprint(string $state): string
    {
        return hash('sha256', $state);
    }
}
