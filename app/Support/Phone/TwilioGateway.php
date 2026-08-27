<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;

/**
 * All Twilio HTTP for Phone. Controllers never call the SDK directly.
 *
 * Platform credentials (subaccount creation) come from config/services.php —
 * never from a tenant row. Connection credentials are the tenant’s BYO or
 * provisioned subaccount key.
 */
interface TwilioGateway
{
    /**
     * @return array{account_sid: string, friendly_name: ?string, auth_token: ?string}
     */
    public function verifyAccount(string $accountSid, string $apiKeySid, string $apiSecret): array;

    /**
     * @return array{account_sid: string, auth_token: ?string}
     */
    public function createSubaccount(string $friendlyName): array;

    /**
     * Subaccounts already under the platform account, for adopting rather than
     * duplicating.
     *
     * @return list<array{account_sid: string, friendly_name: string, status: string}>
     */
    public function listSubaccounts(): array;

    /**
     * Take over a subaccount that already exists.
     *
     * ── Why the caller must supply the auth token ────────────────
     *
     * Twilio returns a subaccount's auth token EXACTLY ONCE, in the response to
     * creating it. Fetching the account again — even as the parent, even with a
     * Main key — returns the field empty. Verified against the live account on
     * 2026-08-27: three existing subaccounts, all empty.
     *
     * §5 validates every webhook against THIS connection's token, because a
     * global one is a tenant-boundary break. So a connection without the token
     * is not a connection we can safely answer webhooks for, and there is no
     * API that can recover it. Somebody reads it off the Console once.
     *
     * The API key is NOT asked for: the parent can mint one on a subaccount it
     * owns, which is the one thing this path can do that BYO cannot.
     *
     * @return array{account_sid: string, api_key_sid: string, api_secret: string, auth_token: string}
     */
    public function adoptSubaccount(string $accountSid, string $authToken): array;

    /**
     * @return array{sid: string, secret: string}
     */
    public function createApiKey(string $accountSid, string $friendlyName = 'EzBizi'): array;

    /**
     * @return array{sid: string}
     */
    public function createTwimlApp(
        string $accountSid,
        string $apiKeySid,
        string $apiSecret,
        string $friendlyName,
        string $voiceUrl,
        ?string $statusCallback = null,
    ): array;

    /**
     * Search available numbers. Callers pass EzBizi snake_case keys; the HTTP
     * gateway maps them to Twilio’s camelCase before the SDK `read()`.
     *
     * @param  array{area_code?: string|int, contains?: string, voice?: bool, sms?: bool, country?: string}  $criteria
     * @return list<array{phone_number: string, friendly_name: ?string, locality: ?string, region: ?string}>
     */
    public function searchAvailable(PhoneConnection $connection, array $criteria): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{sid: string, phone_number: string}
     */
    public function purchaseIncoming(PhoneConnection $connection, string $phoneNumber, array $params = []): array;

    /**
     * @param  array<string, mixed>  $params
     */
    public function updateIncoming(PhoneConnection $connection, string $incomingSid, array $params): void;

    public function releaseIncoming(PhoneConnection $connection, string $incomingSid): void;

    public function accessToken(PhoneConnection $connection, string $identity): string;

    /**
     * @return array{sid: string, status: string}
     */
    public function sendMessage(PhoneConnection $connection, string $from, string $to, string $body): array;

    /**
     * @return array{phone_number: string, caller_name: ?string}
     */
    public function lookup(string $e164): array;

    /**
     * Start a US port-in on the connection’s Twilio account.
     *
     * @param  list<string>  $e164s
     * @param  array<string, mixed>  $params  documents, losing_carrier_information, …
     * @return array{sid: string, status: string}
     */
    public function startPort(PhoneConnection $connection, array $e164s, array $params = []): array;

    /**
     * Trust Hub statuses for Settings. Missing profiles return `pending` — never throw for empty.
     *
     * @return array{customer_profile: string, shaken_stir: string, cnam: string, voice_integrity: string}
     */
    public function trustStatus(PhoneConnection $connection): array;

    /**
     * Submit or advance Trust Hub for this connection. Fake returns pending.
     *
     * @param  array<string, mixed>  $payload
     * @return array{customer_profile: string, shaken_stir: string, cnam: string, voice_integrity: string}
     */
    public function submitTrust(PhoneConnection $connection, array $payload): array;

    /** Raw recording media bytes. */
    public function recordingMedia(PhoneConnection $connection, string $recordingSid): string;

    public function deleteRecording(PhoneConnection $connection, string $recordingSid): void;

    /** Clear Voice / status URLs on the connection’s TwiML App (disconnect). */
    public function clearTwimlApp(PhoneConnection $connection): void;

    /** Close a provisioned subaccount after an explicit disconnect-with-close. */
    public function closeSubaccount(PhoneConnection $connection): void;
}
