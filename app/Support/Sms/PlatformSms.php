<?php

namespace App\Support\Sms;

use App\Support\Phone\TwilioGateway;
use Illuminate\Support\Facades\Log;

/**
 * EzBizi texting its OWN users, from the platform account.
 *
 * ── Why this is not the Phone module ─────────────────────────────
 *
 * The Phone module sends as a TENANT: a customer's business, from a number in
 * that business's Twilio subaccount, carrying that business's carrier
 * registration. This sends as EzBizi — a sign-in code to somebody who has an
 * EzBizi account — and there is no tenant in the sentence.
 *
 * Using a tenant's number for it would be a compliance error rather than a
 * shortcut. A-1 Chimney's toll-free is verified with Twilio under the business
 * name "A1 Chimney", website a-1chimney.com, use case CUSTOMER_CARE, and a
 * sample message about inspection reports. A verification code from EzBizi
 * matches none of that. Carriers filter toll-free traffic against the filing,
 * and the remedy for a mismatch is losing the verification — so the one number
 * that can text at all would be spent on the wrong sender.
 *
 * ── Off, not broken, when there is no number ─────────────────────
 *
 * [configured] is false until `TWILIO_PLATFORM_SMS_FROM` names a number. The
 * screens ask before they offer anything, so an unconfigured platform draws no
 * control at all rather than a switch that silently cannot send. A control that
 * accepts a mobile number and then does nothing is worse than an absent one: it
 * records a consent nobody can act on and leaves somebody waiting for a code.
 *
 * ── Plain HTTP, no SDK ───────────────────────────────────────────
 *
 * One POST with basic auth. The Twilio SDK is already here for provisioning,
 * where it earns its place; a single form post does not need it, and this stays
 * readable by somebody who has never seen the SDK.
 */
class PlatformSms
{
    /** Whether EzBizi has a number of its own to send from. */
    public function configured(): bool
    {
        return $this->from() !== null && $this->sid() !== null && $this->token() !== null;
    }

    /** The number codes come from, for screens that want to name it. */
    public function from(): ?string
    {
        $value = trim((string) config('services.twilio.platform_sms_from'));

        return $value === '' ? null : $value;
    }

    /**
     * Send one message. True when Twilio accepted it.
     *
     * Accepted is not delivered and this cannot tell the difference — Twilio
     * answers the API call long before a carrier answers. A caller that needs
     * delivery has to wait for the status webhook; a caller sending a code just
     * needs to know whether it got as far as the network, which is this.
     */
    public function send(string $to, string $body): bool
    {
        if (! $this->configured()) {
            return false;
        }

        // Through the gateway, so Twilio stays one removable integration
        // (Jose, 2026-10-27) and a test never reaches the real API.
        try {
            app(TwilioGateway::class)->sendPlatformMessage($this->sendingSid(), (string) $this->from(), $to, $body);

            return true;
        } catch (\Throwable $e) {
            // Twilio's own reason, kept. A rejected send is nearly always a
            // specific fixable thing - an unverified toll-free, a landline, a
            // number that has replied STOP.
            Log::warning('platform sms failed', ['error' => $e->getMessage()]);
        }

        return false;
    }

    /**
     * The account the NUMBER is on, which is not always the one we sign as.
     *
     * EzBizi's own toll-free moved off the parent and onto the EzBizi
     * subaccount on 2026-09-16, because it belongs to EzBizi-the-business and
     * the parent is the platform. Twilio scopes a `From` to the account that
     * holds the number, so the URL has to name that account — while the basic
     * auth stays the parent's, which Twilio accepts for its own subaccounts.
     *
     * Falls back to the signing account, so an installation that never moved
     * its number keeps working with nothing set.
     */
    private function sendingSid(): string
    {
        $value = trim((string) config('services.twilio.platform_sms_account_sid'));

        return $value === '' ? (string) $this->sid() : $value;
    }

    private function sid(): ?string
    {
        $value = trim((string) config('services.twilio.platform_account_sid'));

        return $value === '' ? null : $value;
    }

    private function token(): ?string
    {
        $value = trim((string) config('services.twilio.platform_auth_token'));

        return $value === '' ? null : $value;
    }
}
