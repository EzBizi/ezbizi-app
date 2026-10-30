<?php

namespace App\Support\Mail;

use App\Models\SendingIdentity;
use InvalidArgumentException;

/**
 * The answer to "who is this message from", whoever asked.
 *
 * ── Why the platform's identity is one of these too ──────────────
 *
 * EzBizi's own sending identity is not a special case bolted onto the end of the
 * cascade — it is the default the cascade ends at, in exactly the way a
 * SettingDefinition's `default` is what SettingsResolver returns when no scope
 * has set anything. So the platform builds one of these ({@see PlatformIdentity})
 * and a tenant row converts into one ({@see SendingIdentity}), and
 * every caller downstream holds the same object and cannot tell which it got
 * without asking.
 *
 * That matters more than it looks. The alternative — a nullable tenant identity
 * plus "if null, use config" scattered at each call site — is how one of those
 * call sites ends up skipping the verification check, because the platform
 * branch never needed one.
 *
 * ── canSend() is THE function ────────────────────────────────────
 *
 * Everything that is about to put a From on a message asks this and nothing
 * else. Not `state === Verified` at the call site, not a boolean column, not a
 * copy on the model. There is one place that decides, so there is one place to
 * read when the question is "how could an unverified domain possibly have
 * sent", and one place a mutation test can delete to prove the lock is load
 * bearing.
 */
final class MailIdentity
{
    public function __construct(
        public readonly string $fromAddress,
        public readonly string $fromName,
        public readonly ?string $replyTo,
        public readonly MailTransport $transport,
        public readonly SendingState $state,

        /**
         * Where in the hierarchy this came from — 'organization', 'company',
         * 'franchise', or null for the platform.
         *
         * Carried so a screen can say "inherited from A-1 Chimney" rather than
         * only showing the address. The settings screen learned the same lesson:
         * without the source, an administrator changes the wrong level and
         * wonders why nothing happened.
         */
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
        public readonly ?string $sourceName = null,

        /** A sentence about the current state, when the system has one to give. */
        public readonly ?string $stateReason = null,
    ) {
        if (! str_contains($fromAddress, '@')) {
            throw new InvalidArgumentException(
                "'{$fromAddress}' is not an email address, so nothing could be sent from it."
            );
        }
    }

    /**
     * May a message actually go out under this identity?
     *
     * The single question. A transport that requires proof of the domain gets it
     * checked; the platform's own transport has nothing to prove to itself,
     * which is why the check is asked of the transport rather than assumed.
     */
    public function canSend(): bool
    {
        if (! $this->transport->requiresDomainProof()) {
            return true;
        }

        return $this->state === SendingState::Verified;
    }

    /**
     * Why not, in a sentence somebody can act on (R8) — null when it can send.
     *
     * The state's own sentence unless the system recorded something more
     * specific when it last looked, in which case both are given: the generic
     * one says what to do and the recorded one says what came back.
     */
    public function refusal(): ?string
    {
        if ($this->canSend()) {
            return null;
        }

        $sentence = $this->state->refusal($this->fromAddress)
            ?? "Mail cannot be sent as {$this->fromAddress} yet. Open the sending address "
              .'and start verification.';

        return $this->stateReason === null
            ? $sentence
            : $sentence.' '.$this->stateReason;
    }

    /** The domain half, lower-cased — what gets proved, and what DNS is asked about. */
    public function domain(): string
    {
        return strtolower(substr(strrchr($this->fromAddress, '@') ?: '@', 1));
    }

    /** "A-1 Chimney <info@a-1chimney.com>" — for a log line, never for a header. */
    public function describe(): string
    {
        return "{$this->fromName} <{$this->fromAddress}>";
    }
}
