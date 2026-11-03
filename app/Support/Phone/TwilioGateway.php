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
     * `type` picks which of Twilio's three list resources is read — local,
     * toll_free or mobile. They are genuinely different resources, not a
     * filter: a toll-free search sent to the local list quietly answers with
     * local numbers, and you find out after buying the wrong kind of line.
     *
     * @param  array{type?: string, country?: string, area_code?: string|int, contains?: string, voice?: bool, sms?: bool, mms?: bool, near_number?: string, near_lat_long?: string, distance?: int, in_postal_code?: string, in_region?: string, in_rate_center?: string, in_lata?: string, in_locality?: string, exclude_all_address_required?: bool, exclude_local_address_required?: bool, exclude_foreign_address_required?: bool, beta?: bool, limit?: int}  $criteria
     * @return list<array{phone_number: string, friendly_name: ?string, locality: ?string, region: ?string, postal_code: ?string, iso_country: ?string, lata: ?string, rate_center: ?string, latitude: ?float, longitude: ?float, address_requirements: ?string, beta: bool, capabilities: array<string, bool>}>
     */
    public function searchAvailable(PhoneConnection $connection, array $criteria): array;

    /**
     * Daily usage for this connection's subaccount.
     *
     * Every subaccount reports its own usage, which is the only way to tell
     * what one company's phone service cost when EzBizi pays one bill for all
     * of them. Twilio does not keep these forever, so this is collected
     * nightly rather than asked for when somebody wants a number.
     *
     * Dates are Y-m-d in the account's own timezone, which is Twilio's rule,
     * not ours.
     *
     * @return list<array{category: string, description: ?string, start_date: ?string, usage: string, usage_unit: ?string, count: string, count_unit: ?string, price: string, price_unit: ?string}>
     */
    public function usageDaily(PhoneConnection $connection, string $startDate, string $endDate): array;

    /**
     * The countries this account may buy numbers in.
     *
     * Read from Twilio rather than hard-coded: the list changes, and a stale
     * constant would either hide a country we can sell or offer one that
     * errors at purchase.
     *
     * @return list<array{country_code: string, country: string}>
     */
    public function availableCountries(PhoneConnection $connection): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{sid: string, phone_number: string}
     */
    /**
     * Numbers this Twilio account already holds.
     *
     * Adopting an account means adopting what is on it. A company that has
     * been answering five lines for a year does not want to buy them again,
     * and a Numbers screen that showed none of them would read as broken.
     *
     * @return list<array{sid: string, phone_number: string, friendly_name: string, voice_url: string, sms_url: string}>
     */
    public function listIncoming(PhoneConnection $connection): array;

    /**
     * Numbers held on any account the platform credentials reach (the main
     * profile, a parent), for the nightly line audit. Read-only.
     *
     * @return list<array{sid: string, phone_number: string, friendly_name: string, voice_url: string, sms_url: string}>
     */
    public function listAccountIncoming(string $accountSid): array;

    public function purchaseIncoming(PhoneConnection $connection, string $phoneNumber, array $params = []): array;

    /**
     * One line's live state on Twilio.
     *
     * Read rather than remembered for the fields Twilio owns and can change
     * without telling us — above all `emergency_status`, which Twilio flips to
     * inactive by itself when an address stops validating. A screen that
     * showed our stored copy would keep saying 911 works after it stopped.
     *
     * @return array{sid: string, phone_number: string, friendly_name: string, capabilities: array<string, bool>, emergency_status: ?string, emergency_address_sid: ?string, voice_caller_id_lookup: bool, address_requirements: ?string}
     */
    public function fetchIncoming(PhoneConnection $connection, string $incomingSid): array;

    /**
     * Addresses registered on this Twilio account.
     *
     * Twilio requires a validated emergency address before a US line can
     * reach 911, and it is an account-level resource rather than a property
     * of the number — one address serves every line at that premises.
     *
     * @return list<array{sid: string, friendly_name: ?string, customer_name: string, street: string, city: string, region: string, postal_code: string, iso_country: string, emergency_enabled: bool, validated: bool}>
     */
    public function listAddresses(PhoneConnection $connection): array;

    /**
     * Register an address. `emergency_enabled` is what makes it usable for 911.
     *
     * @param  array<string, mixed>  $address
     * @return array{sid: string, validated: bool, emergency_enabled: bool}
     */
    public function createAddress(PhoneConnection $connection, array $address): array;

    /**
     * @param  array<string, mixed>  $params
     */
    public function updateIncoming(PhoneConnection $connection, string $incomingSid, array $params): void;

    public function releaseIncoming(PhoneConnection $connection, string $incomingSid): void;

    /**
     * Register the key that lets Twilio wake a handset, and return its SID.
     *
     * `$platform` is 'ios' or 'android'. `$material` is the Apple VoIP
     * certificate and private key, or the Firebase server key — whichever the
     * platform uses. Neither ever comes back out of Twilio, which is why the
     * SID it returns is the only thing worth storing.
     *
     * Per CONNECTION and not once for EzBizi, because a push credential
     * belongs to the Twilio account that mints the token and each company
     * mints from its own subaccount.
     *
     * @param  array<string, mixed>  $material
     */
    public function createPushCredential(
        PhoneConnection $connection,
        string $platform,
        array $material,
    ): string;

    /**
     * A Voice token for one person on one connection.
     *
     * `$platform` is 'ios' or 'android' when the token is going to a PHONE,
     * and null for a browser. It decides whether the grant names a push
     * credential — without one Twilio has no way to wake a locked handset, so
     * the device registers, places calls, and never rings.
     */
    /**
     * How long a minted access token lives.
     *
     * Named here because two places need the same number and they are in
     * different languages: the gateway that mints the token, and the desk that
     * has to ask for another one before it dies. The desk used to know nothing
     * about it — it asked for one token at launch and went quiet an hour later
     * with no error, which cost 27 of 29 inbound calls over two days.
     *
     * A client that is TOLD the lifetime cannot drift from it. A client with a
     * hard-coded 3600 can, and would do so silently the day this changes.
     */
    public const TOKEN_TTL_SECONDS = 3600;

    public function accessToken(PhoneConnection $connection, string $identity, ?string $platform = null): string;

    /**
     * @return array{sid: string, status: string}
     */
    /**
     * Ring somebody's own phone and, when they pick up, connect them onward.
     *
     * ── Why this exists at all ──────────────────────────────────────
     *
     * There is no Twilio Voice SDK for Windows, macOS or Linux — the softphone
     * is a browser script — so the desktop app can never place a call itself.
     * Rather than tell three platforms they cannot use the phone, this uses the
     * REST API, which every platform can reach: Twilio calls the person's own
     * handset, they answer, and Twilio then dials the customer and bridges the
     * two. No SDK, no microphone permission, no WebRTC.
     *
     * The TwiML is passed INLINE rather than hosted, so this needs no webhook
     * of its own. Twilio caps that at 4,000 characters, which a Dial is nowhere
     * near.
     *
     * @param  string  $from  A number this account holds. Twilio refuses any
     *                        other, and the refusal is a failed call rather
     *                        than an error anybody sees.
     * @return array{sid: string, status: string}
     */
    public function createCall(
        PhoneConnection $connection,
        string $from,
        string $to,
        string $twiml,
    ): array;

    /**
     * Point a call that is ALREADY UP at new TwiML.
     *
     * This is how a live call moves between devices. The customer's leg is
     * redirected, which ends the `<Dial>` currently bridging it and starts a
     * new one — so the handset that was on the call drops and whichever device
     * the new document names begins to ring, with the customer held on the
     * whole time.
     *
     * The SID must be the CUSTOMER's leg. Redirecting the client leg would
     * take the phone away from the person holding it and leave the customer
     * bridged to nothing.
     */
    public function redirectCall(PhoneConnection $connection, string $callSid, string $twiml): void;

    /**
     * The device legs Twilio made for one inbound call.
     *
     * A `<Dial>` with several `<Client>` nouns produces one CHILD call per
     * noun, and the parent row in `phone_calls` knows nothing about them. This
     * is the only way to find out which of a person's devices Twilio is
     * currently ringing — needed because answering on one of them does not
     * stop the others by itself when they were rung by separate Dials, and
     * DECLINING on one never stops the others at all.
     *
     * @return list<array{sid: string, to: string, status: string}>
     */
    public function childLegs(PhoneConnection $connection, string $parentCallSid): array;

    /**
     * Hang up one leg, leaving the rest of the call alone.
     *
     * Used to stop a person's OTHER phones ringing once they have dealt with a
     * call on one of them. Deliberately per-leg: ending the parent would take
     * the call away from the colleague who just answered it.
     */
    public function endCall(PhoneConnection $connection, string $callSid): void;

    /**
     * How many callers are waiting in each queue on this account, right now.
     *
     * ── Why this is asked of Twilio and not of our own tables ───
     *
     * Because Twilio is where the callers actually are. A row in `phone_calls`
     * says `ringing` for somebody listening to a menu, somebody ringing a
     * team, and somebody holding in a queue alike — nothing here records which
     * step a call is at, and a column that did would go stale exactly the way
     * `status` already does when a final callback never arrives. Twilio's
     * queue knows its own size and cannot be wrong about it.
     *
     * Keyed by the queue's FRIENDLY NAME, which is `ezbizi-<ringGroupId>` —
     * see `PhoneRingGroup::queueName()`. A group that has never held anybody
     * has no queue resource at all, so a missing key means zero rather than
     * unknown.
     *
     * @return array<string, array{waiting: int, average_wait: int}>
     */
    public function queueSizes(PhoneConnection $connection): array;

    /**
     * $accountSid names the account that holds $from when it is not the
     * connection's (PhoneNumber::account_sid); null sends as the connection.
     */
    /**
     * A text from EzBizi's own number (verification codes), not a tenant's:
     * signed by the platform parent, addressed to $accountSid, the account
     * holding $from. Throws with Twilio's reason when refused.
     *
     * @return array{sid: string}
     */
    public function sendPlatformMessage(string $accountSid, string $from, string $to, string $body): array;

    public function sendMessage(PhoneConnection $connection, string $from, string $to, string $body, ?string $accountSid = null): array;

    /**
     * @return array{phone_number: string, caller_name: ?string}
     */
    /**
     * Move a number from one subaccount to another under the same parent.
     *
     * Twilio does this by updating the IncomingPhoneNumber's AccountSid, and
     * it only works between subaccounts of one parent — which is exactly the
     * shape EzBizi provisions, so a number can follow a company without being
     * released and re-bought. Releasing would lose the number: somebody else
     * can buy it within the hour, and the business's printed vans still say it.
     *
     * `$params` rewrites the webhooks in the same call. They must move with
     * the number: EzBizi's voice and SMS URLs carry the organization and the
     * CONNECTION in their path, so a moved number left pointing at the old URL
     * keeps being answered by the old company's ring groups and hours.
     *
     * @param  array<string, mixed>  $params
     */
    public function moveIncomingToAccount(
        PhoneConnection $from,
        string $incomingSid,
        string $targetAccountSid,
        array $params = [],
    ): void;

    public function lookup(string $e164): array;

    /**
     * Lookup v2 with line type and carrier — what a number actually IS.
     *
     * Separate from [lookup], which asks only for caller_name and is called on
     * every inbound call. This one is asked for deliberately by a person and
     * costs money per call, so it is never on a hot path.
     *
     * `valid` is the free part; line type intelligence is billed. A number
     * that fails validation returns valid=false rather than throwing, because
     * "that is not a real number" is an answer, not a failure.
     *
     * @return array{phone_number: string, valid: bool, national_format: ?string, country_code: ?string, line_type: ?string, carrier_name: ?string, caller_name: ?string}
     */
    public function lookupDetailed(string $e164): array;

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
