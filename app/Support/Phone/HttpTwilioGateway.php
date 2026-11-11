<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
use Twilio\Rest\Client;
use Twilio\Rest\Numbers\V1\PortingPortInModels;

/**
 * Live Twilio REST / JWT. Bound outside tests.
 */
final class HttpTwilioGateway implements TwilioGateway
{
    /**
     * A subaccount for this business, reusing one of the same name if it is
     * already there.
     *
     * Reuse rather than create, because this is retried. The token check
     * downstream used to fail AFTER the account existed, so every retry left
     * another identically-named subaccount behind — two "A-1 Chimney" accounts
     * appeared exactly that way. Creating is the fallback, not the default.
     */
    public function createSubaccount(string $friendlyName): array
    {
        $client = $this->platformClient();

        foreach ($this->listSubaccounts() as $existing) {
            if (strcasecmp(trim($existing['friendly_name']), trim($friendlyName)) !== 0) {
                continue;
            }

            // Live ones only. A closed account keeps its name, so closing a
            // duplicate leaves two rows answering to it — and Twilio refuses
            // every write to the dead one with "Account does not exist",
            // which reads as a server error rather than as what it is.
            if ($existing['status'] !== 'active') {
                continue;
            }

            $account = $client->api->v2010->accounts($existing['account_sid'])->fetch();

            return [
                'account_sid' => $account->sid,
                'auth_token' => $account->authToken,
            ];
        }

        $account = $client->api->v2010->accounts->create([
            'friendlyName' => $friendlyName,
        ]);

        return [
            'account_sid' => $account->sid,
            'auth_token' => $account->authToken,
        ];
    }

    public function listSubaccounts(): array
    {
        [$platformSid] = $this->platformCredentials();

        $out = [];

        foreach ($this->platformClient()->api->v2010->accounts->read([], 200) as $account) {
            // The platform account itself comes back in its own list. It is not
            // adoptable: connecting a tenant to the account that provisions
            // every other tenant would put one customer's Settings in charge of
            // everybody's phone service.
            if ($account->sid === $platformSid) {
                continue;
            }

            // Closed and suspended accounts are not offerable either: they
            // cannot be written to, so adopting one would fail at the first
            // API call after the customer had already chosen it.
            if ((string) $account->status !== 'active') {
                continue;
            }

            $out[] = [
                'account_sid' => (string) $account->sid,
                'friendly_name' => (string) $account->friendlyName,
                'status' => (string) $account->status,
            ];
        }

        return $out;
    }

    public function adoptSubaccount(string $accountSid, string $authToken): array
    {
        [$platformSid] = $this->platformCredentials();

        if ($accountSid === $platformSid) {
            throw new RuntimeException(
                'That is the EzBizi platform account itself, not one of its phone accounts.'
            );
        }

        $owned = false;
        foreach ($this->listSubaccounts() as $sub) {
            if ($sub['account_sid'] === $accountSid) {
                $owned = true;
                break;
            }
        }

        // Checked before the token is tried, so a SID belonging to a stranger's
        // Twilio is refused as "not ours" rather than as "wrong token" — which
        // would otherwise read as an invitation to keep guessing.
        if (! $owned) {
            throw new RuntimeException(
                'That phone account is not one EzBizi manages. Connect it as your own Twilio instead.'
            );
        }

        // Account SID + auth token is the one credential pair that proves the
        // token is right; an API key cannot verify it, because Twilio signs
        // webhooks with the token and nothing else.
        try {
            (new Client($accountSid, $authToken))->api->v2010->accounts($accountSid)->fetch();
        } catch (Throwable) {
            throw new RuntimeException(
                'That auth token does not match this phone account. Copy it from the account it belongs to.'
            );
        }

        $key = $this->createApiKey($accountSid, 'EzBizi');

        return [
            'account_sid' => $accountSid,
            'api_key_sid' => $key['sid'],
            'api_secret' => $key['secret'],
            'auth_token' => $authToken,
        ];
    }

    public function createApiKey(string $accountSid, string $friendlyName = 'EzBizi'): array
    {
        $key = $this->platformClientForAccount($accountSid)->account->newKeys->create([
            'friendlyName' => $friendlyName,
        ]);

        return [
            'sid' => $key->sid,
            'secret' => $key->secret,
        ];
    }

    public function createTwimlApp(
        string $accountSid,
        string $apiKeySid,
        string $apiSecret,
        string $friendlyName,
        string $voiceUrl,
        ?string $statusCallback = null,
    ): array {
        $params = [
            'friendlyName' => $friendlyName,
            'voiceMethod' => 'POST',
            'voiceUrl' => $voiceUrl,
        ];
        if ($statusCallback !== null) {
            $params['statusCallback'] = $statusCallback;
            $params['statusCallbackMethod'] = 'POST';
        }

        $app = $this->client($accountSid, $apiKeySid, $apiSecret)
            ->applications
            ->create($params);

        return ['sid' => $app->sid];
    }

    public function searchAvailable(PhoneConnection $connection, array $criteria): array
    {
        $normalized = self::normalizeSearchCriteria($criteria);

        $country = strtoupper((string) ($normalized['country'] ?? 'US'));
        $type = (string) ($normalized['type'] ?? 'local');
        $limit = (int) ($normalized['limit'] ?? 20);

        // Routing keys, not filters. Twilio's read() ignores what it does not
        // know, so leaving these in would be silent rather than loud.
        unset($normalized['country'], $normalized['type'], $normalized['limit']);

        $inCountry = $this->connectionClient($connection)->availablePhoneNumbers($country);

        $list = match ($type) {
            'toll_free' => $inCountry->tollFree,
            'mobile' => $inCountry->mobile,
            default => $inCountry->local,
        };

        $out = [];
        foreach ($list->read($normalized, $limit) as $number) {
            $out[] = self::availableRow($number);
        }

        return $out;
    }

    /**
     * Twilio answers with either a plain map or a PhoneNumberCapabilities
     * object, whose properties are protected: an (array) cast of that object
     * stored keys like "*sms" wrapped in NUL bytes and every flag read as missing.
     *
     * @return array<string, bool>
     */
    private static function capabilities(mixed $raw): array
    {
        if ($raw instanceof \Twilio\Base\PhoneNumberCapabilities) {
            return ['voice' => $raw->getVoice(), 'sms' => $raw->getSms(), 'mms' => $raw->getMms(), 'fax' => $raw->getFax()];
        }

        $capabilities = [];
        foreach ((array) ($raw ?? []) as $name => $enabled) {
            $capabilities[strtolower((string) $name)] = (bool) $enabled;
        }

        return $capabilities;
    }

    /**
     * One search hit, flattened.
     *
     * `address_requirements` is the field that decides whether a number can
     * actually be bought: `none`, `any`, `local` or `foreign`. Twilio lists
     * numbers it will then refuse to sell without a registered Address, so
     * hiding this turns a knowable refusal into a mystery at purchase.
     *
     * @return array<string, mixed>
     */
    private static function availableRow(object $number): array
    {
        // Twilio answers with mixed-case capability keys — voice, SMS, MMS,
        // fax. EzBizi speaks one case everywhere.
        $capabilities = self::capabilities($number->capabilities ?? null);

        return [
            'phone_number' => (string) $number->phoneNumber,
            'friendly_name' => $number->friendlyName,
            'locality' => $number->locality,
            'region' => $number->region,
            'postal_code' => $number->postalCode,
            'iso_country' => $number->isoCountry,
            'lata' => $number->lata,
            'rate_center' => $number->rateCenter,
            'latitude' => $number->latitude === null ? null : (float) $number->latitude,
            'longitude' => $number->longitude === null ? null : (float) $number->longitude,
            'address_requirements' => $number->addressRequirements,
            'beta' => (bool) $number->beta,
            'capabilities' => $capabilities,
        ];
    }

    public function fetchIncoming(PhoneConnection $connection, string $incomingSid, ?string $accountSid = null): array
    {
        // A number held in a sub-account is only found there (404 otherwise).
        $number = (filled($accountSid) ? $this->platformClientForAccount((string) $accountSid) : $this->connectionClient($connection))
            ->incomingPhoneNumbers($incomingSid)
            ->fetch();

        $capabilities = self::capabilities($number->capabilities ?? null);

        return [
            'sid' => (string) $number->sid,
            'phone_number' => (string) $number->phoneNumber,
            'friendly_name' => (string) $number->friendlyName,
            'capabilities' => $capabilities,
            'emergency_status' => $number->emergencyStatus,
            'emergency_address_sid' => $number->emergencyAddressSid,
            'voice_caller_id_lookup' => (bool) $number->voiceCallerIdLookup,
            'address_requirements' => $number->addressRequirements,
        ];
    }

    public function listAddresses(PhoneConnection $connection): array
    {
        $out = [];

        foreach ($this->connectionClient($connection)->addresses->read([], 200) as $address) {
            $out[] = [
                'sid' => (string) $address->sid,
                'friendly_name' => $address->friendlyName,
                'customer_name' => (string) $address->customerName,
                'street' => (string) $address->street,
                'city' => (string) $address->city,
                'region' => (string) $address->region,
                'postal_code' => (string) $address->postalCode,
                'iso_country' => (string) $address->isoCountry,
                'emergency_enabled' => (bool) $address->emergencyEnabled,
                'validated' => (bool) $address->validated,
            ];
        }

        return $out;
    }

    public function createAddress(PhoneConnection $connection, array $address): array
    {
        $created = $this->connectionClient($connection)->addresses->create(
            $address['customer_name'],
            $address['street'],
            $address['city'],
            $address['region'],
            $address['postal_code'],
            $address['iso_country'],
            array_filter([
                'friendlyName' => $address['friendly_name'] ?? null,

                // The whole point of registering it. Without this Twilio
                // stores the address and 911 still has nowhere to send anyone.
                'emergencyEnabled' => true,
            ], static fn ($value) => $value !== null),
        );

        return [
            'sid' => (string) $created->sid,
            'validated' => (bool) $created->validated,
            'emergency_enabled' => (bool) $created->emergencyEnabled,
        ];
    }

    public function usageDaily(PhoneConnection $connection, string $startDate, string $endDate): array
    {
        $out = [];

        $records = $this->connectionClient($connection)
            ->usage
            ->records
            ->daily
            ->read(['startDate' => $startDate, 'endDate' => $endDate], 1000);

        foreach ($records as $record) {
            // Everything stays a string here. These are money and minutes, and
            // casting to float in the gateway would round before the caller
            // ever sees the value Twilio actually sent.
            $out[] = [
                'category' => (string) $record->category,
                'description' => $record->description,
                'start_date' => $record->startDate?->format('Y-m-d'),
                'usage' => (string) $record->usage,
                'usage_unit' => $record->usageUnit,
                'count' => (string) $record->count,
                'count_unit' => $record->countUnit,
                'price' => (string) $record->price,
                'price_unit' => $record->priceUnit,
            ];
        }

        return $out;
    }

    public function availableCountries(PhoneConnection $connection): array
    {
        $out = [];

        foreach ($this->connectionClient($connection)->availablePhoneNumbers->read([], 200) as $country) {
            $out[] = [
                'country_code' => (string) $country->countryCode,
                'country' => (string) $country->country,
            ];
        }

        return $out;
    }

    /**
     * Map EzBizi search keys to Twilio AvailablePhoneNumber options.
     *
     * Twilio’s LocalList::read ignores unknown keys, so leaving `area_code`
     * unmapped silently returns an unfiltered list. Callers (and Task 6 buy)
     * speak snake_case; this is the only place that translation happens.
     *
     * @param  array<string, mixed>  $criteria
     * @return array<string, mixed>
     */
    public static function normalizeSearchCriteria(array $criteria): array
    {
        $map = [
            'area_code' => 'areaCode',
            'voice' => 'voiceEnabled',
            'sms' => 'smsEnabled',
            'mms' => 'mmsEnabled',
            'near_number' => 'nearNumber',
            'near_lat_long' => 'nearLatLong',
            'in_postal_code' => 'inPostalCode',
            'in_region' => 'inRegion',
            'in_rate_center' => 'inRateCenter',
            'in_locality' => 'inLocality',
            'in_lata' => 'inLata',

            'exclude_all_address_required' => 'excludeAllAddressRequired',
            'exclude_local_address_required' => 'excludeLocalAddressRequired',
            'exclude_foreign_address_required' => 'excludeForeignAddressRequired',
        ];

        $out = [];
        foreach ($criteria as $key => $value) {
            $out[$map[$key] ?? $key] = $value;
        }

        return $out;
    }

    public function listIncoming(PhoneConnection $connection): array
    {
        $out = [];

        foreach ($this->connectionClient($connection)->incomingPhoneNumbers->read([], 200) as $number) {
            $out[] = [
                'sid' => (string) $number->sid,
                'phone_number' => (string) $number->phoneNumber,
                'friendly_name' => (string) $number->friendlyName,
                'voice_url' => (string) $number->voiceUrl,
                'sms_url' => (string) $number->smsUrl,
            ];
        }

        return $out;
    }

    public function listAccountIncoming(string $accountSid): array
    {
        $response = Http::withBasicAuth(
            (string) config('services.twilio.platform_account_sid'),
            (string) config('services.twilio.platform_auth_token'),
        )->timeout(15)->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/IncomingPhoneNumbers.json", ['PageSize' => 1000]);

        if (! $response->successful()) {
            throw new RuntimeException("Twilio refused the number list ({$response->status()})");
        }

        return array_map(fn (array $n) => [
            'sid' => (string) $n['sid'],
            'phone_number' => (string) $n['phone_number'],
            'friendly_name' => (string) ($n['friendly_name'] ?? ''),
            'voice_url' => (string) ($n['voice_url'] ?? ''),
            'sms_url' => (string) ($n['sms_url'] ?? ''),
        ], (array) $response->json('incoming_phone_numbers', []));
    }

    public function platformCall(string $accountSid, string $from, string $to, string $twiml): string
    {
        $response = $this->platformHttp()->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls.json", ['From' => $from, 'To' => $to, 'Twiml' => $twiml]);

        if (! $response->successful()) {
            throw new RuntimeException("Twilio refused the test call ({$response->status()}): ".$response->json('message', ''));
        }

        return (string) $response->json('sid');
    }

    public function platformCallStatus(string $accountSid, string $callSid): array
    {
        $response = $this->platformHttp()->get("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls/{$callSid}.json");

        return ['status' => (string) $response->json('status', 'unknown'), 'duration' => (int) $response->json('duration', 0)];
    }

    private function platformHttp(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.twilio.platform_account_sid'),
            (string) config('services.twilio.platform_auth_token'),
        )->timeout(15);
    }

    public function purchaseIncoming(PhoneConnection $connection, string $phoneNumber, array $params = []): array
    {
        $created = $this->connectionClient($connection)->incomingPhoneNumbers->create([
            'phoneNumber' => $phoneNumber,
            ...$params,
        ]);

        return [
            'sid' => $created->sid,
            'phone_number' => $created->phoneNumber,
        ];
    }

    public function updateIncoming(PhoneConnection $connection, string $incomingSid, array $params): void
    {
        $this->connectionClient($connection)
            ->incomingPhoneNumbers($incomingSid)
            ->update($params);
    }

    public function moveIncomingToAccount(
        PhoneConnection $from,
        string $incomingSid,
        string $targetAccountSid,
        array $params = [],
    ): void {
        // Authenticated as the PARENT, acting as the source subaccount. The
        // source's own API key can update its numbers but cannot hand one to a
        // sibling — only the parent sees both sides of the move.
        $this->platformClientForAccount((string) $from->account_sid)
            ->incomingPhoneNumbers($incomingSid)
            ->update([
                'accountSid' => $targetAccountSid,
                ...$params,
            ]);
    }

    public function releaseIncoming(PhoneConnection $connection, string $incomingSid): void
    {
        $this->connectionClient($connection)
            ->incomingPhoneNumbers($incomingSid)
            ->delete();
    }

    public function createPushCredential(
        PhoneConnection $connection,
        string $platform,
        array $material,
    ): string {
        $credentials = $this->pushCredentialClient($connection)
            ->notify
            ->v1
            ->credentials;

        $name = 'EzBizi '.$platform.' — '.$connection->id;

        $instance = match ($platform) {
            'ios' => $credentials->create('apn', [
                'friendlyName' => $name,
                'certificate' => (string) $material['certificate'],
                'privateKey' => (string) $material['private_key'],

                // Sandbox APNs is a DIFFERENT service, not a softer one. A
                // credential on the wrong side of this line delivers nothing
                // and reports nothing: TestFlight and the App Store are both
                // production, only a Debug build off Xcode is sandbox.
                'sandbox' => (bool) ($material['sandbox'] ?? false),
            ]),
            'android' => $credentials->create('fcm', [
                'friendlyName' => $name,
                'secret' => (string) $material['secret'],
            ]),
            default => throw new RuntimeException(
                "There is no push credential for '{$platform}'."
            ),
        };

        // THE RESPONSE SAYS WHO OWNS IT. Read it.
        //
        // When this created three credentials on the parent account, the
        // create response carried `account_sid` = the parent on every one of
        // them. The answer was in my hand and I returned the SID without
        // looking at it, so the command reported three successes and stored
        // three SIDs no subaccount token can use.
        //
        // Costs nothing — no extra call, the field is already in the payload —
        // and it makes the whole class of mistake loud instead of silent. A
        // create that returns 201 and a real id has told you the write
        // happened, not where.
        $this->assertOwnedBy((string) $instance->accountSid, $connection);

        return (string) $instance->sid;
    }

    /**
     * Refuse a credential that belongs to a different Twilio account.
     *
     * Separate and tiny so it can be tested without a network: the guard is
     * the part that was missing, not the request.
     */
    private function assertOwnedBy(string $ownerAccountSid, PhoneConnection $connection): void
    {
        $expected = (string) $connection->account_sid;

        if ($ownerAccountSid === $expected) {
            return;
        }

        throw new RuntimeException(
            'Twilio created that push credential on account '
            .($ownerAccountSid === '' ? '(none reported)' : $ownerAccountSid)
            .', not on '.$expected.'. A token minted from this connection '
            .'cannot use it, so it has not been stored.'
        );
    }

    public function accessToken(PhoneConnection $connection, string $identity, ?string $platform = null): string
    {
        $secret = (string) $connection->credentials;
        $apiKeySid = (string) $connection->api_key_sid;
        if ($apiKeySid === '' || $secret === '') {
            throw new RuntimeException('This phone connection has no API key to mint a Voice token.');
        }

        $token = new AccessToken(
            (string) $connection->account_sid,
            $apiKeySid,
            $secret,
            TwilioGateway::TOKEN_TTL_SECONDS,
            $identity,
        );

        $grant = new VoiceGrant;
        $grant->setIncomingAllow(true);
        if ($connection->twiml_app_sid) {
            $grant->setOutgoingApplicationSid((string) $connection->twiml_app_sid);
        }

        // What makes a phone ring. A browser holds its own signalling socket
        // open and hears the call down it; a locked handset has no socket, so
        // Twilio sends a VoIP push instead — and only if this claim tells it
        // which credential to send with. Missing, the phone registers, dials
        // out, calls itself connected, and never rings.
        //
        // Null while the company has no credential yet: the desk phone must
        // keep working while the mobile one is being provisioned.
        $push = $connection->pushCredentialFor($platform);
        if ($push !== null) {
            $grant->setPushCredentialSid($push);
        }

        $token->addGrant($grant);

        return $token->toJWT();
    }

    public function createCall(
        PhoneConnection $connection,
        string $from,
        string $to,
        string $twiml,
    ): array {
        $call = $this->connectionClient($connection)->calls->create($to, $from, [
            'twiml' => $twiml,
        ]);

        return [
            'sid' => $call->sid,
            'status' => (string) $call->status,
        ];
    }

    public function redirectCall(PhoneConnection $connection, string $callSid, string $twiml): void
    {
        $this->connectionClient($connection)
            ->calls($callSid)
            ->update(['twiml' => $twiml]);
    }

    public function childLegs(PhoneConnection $connection, string $parentCallSid): array
    {
        $out = [];

        foreach ($this->connectionClient($connection)->calls->read(
            ['parentCallSid' => $parentCallSid],
            30,
        ) as $leg) {
            $out[] = [
                'sid' => (string) $leg->sid,
                'to' => (string) $leg->to,
                'status' => (string) $leg->status,
            ];
        }

        return $out;
    }

    public function endCall(PhoneConnection $connection, string $callSid): void
    {
        $this->connectionClient($connection)
            ->calls($callSid)
            ->update(['status' => 'completed']);
    }

    public function queueSizes(PhoneConnection $connection): array
    {
        $out = [];

        foreach ($this->connectionClient($connection)->queues->read([], 50) as $queue) {
            $out[(string) $queue->friendlyName] = [
                'waiting' => (int) $queue->currentSize,
                'average_wait' => (int) $queue->averageWaitTime,
            ];
        }

        return $out;
    }

    public function sendPlatformMessage(string $accountSid, string $from, string $to, string $body): array
    {
        $response = Http::asForm()
            ->withBasicAuth(
                (string) config('services.twilio.platform_account_sid'),
                (string) config('services.twilio.platform_auth_token'),
            )
            ->timeout(15)
            ->post(
                "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json",
                ['To' => $to, 'From' => $from, 'Body' => $body],
            );

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Twilio refused the text (%s %s): %s',
                $response->status(),
                (string) $response->json('code'),
                (string) $response->json('message'),
            ));
        }

        return ['sid' => (string) $response->json('sid')];
    }

    public function sendMessage(PhoneConnection $connection, string $from, string $to, string $body, ?string $accountSid = null, array $mediaUrls = []): array
    {
        // A `From` only sends from the account holding it, so a number held
        // elsewhere in the tree sends as that account, signed by the parent.
        $client = filled($accountSid)
            ? $this->platformClientForAccount((string) $accountSid)
            : $this->connectionClient($connection);

        $message = $client->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ] + ($mediaUrls === [] ? [] : ['mediaUrl' => $mediaUrls]));

        return [
            'sid' => $message->sid,
            'status' => (string) $message->status,
        ];
    }

    public function lookup(string $e164): array
    {
        $result = $this->platformClient()->lookups->v2->phoneNumbers($e164)->fetch([
            'fields' => 'caller_name',
        ]);

        $caller = $result->callerName;

        return [
            'phone_number' => $result->phoneNumber ?? $e164,
            'caller_name' => is_array($caller) ? ($caller['caller_name'] ?? null) : null,
        ];
    }

    public function lookupDetailed(string $e164): array
    {
        $result = $this->platformClient()->lookups->v2->phoneNumbers($e164)->fetch([
            'fields' => 'line_type_intelligence,caller_name',
        ]);

        // Twilio returns these as associative arrays, and returns them as null
        // when the paid field could not be resolved — which is not the same as
        // the number being invalid.
        $line = is_array($result->lineTypeIntelligence) ? $result->lineTypeIntelligence : [];
        $caller = is_array($result->callerName) ? $result->callerName : [];

        return [
            'phone_number' => (string) ($result->phoneNumber ?? $e164),

            // `valid` is the free half of Lookup and the half that matters
            // most: a number nobody can dial is worth knowing about whether or
            // not the paid carrier data resolved.
            'valid' => (bool) $result->valid,
            'national_format' => $result->nationalFormat,
            'country_code' => $result->countryCode,
            'line_type' => $line['type'] ?? null,
            'carrier_name' => $line['carrier_name'] ?? null,
            'caller_name' => $caller['caller_name'] ?? null,
        ];
    }

    /**
     * The losing carrier's details as Twilio's model takes them: the address
     * has to be its own model object, not an array.
     *
     * @return array<string, mixed>
     */
    private static function losingCarrier(mixed $carrier): array
    {
        if (! is_array($carrier)) {
            return [];
        }

        if (is_array($carrier['address'] ?? null)) {
            $carrier['address'] = PortingPortInModels::createNumbersV1PortingAddress($carrier['address']);
        }

        return $carrier;
    }

    public function startPort(PhoneConnection $connection, array $e164s, array $params = []): array
    {
        $phoneNumbers = array_map(
            fn (string $e164) => PortingPortInModels::createNumbersV1PortingPortInCreatePhoneNumbers(array_filter([
                'phone_number' => $e164,
                // US mobile carriers require it; one PIN covers the port.
                'pin' => $params['pin'] ?? null,
            ])),
            $e164s,
        );

        $payload = [
            'account_sid' => (string) $connection->account_sid,
            'phone_numbers' => $phoneNumbers,
            'documents' => $params['documents'] ?? [],
            'losing_carrier_information' => $params['losing_carrier_information']
                ?? PortingPortInModels::createNumbersV1PortingLosingCarrierInformation(
                    self::losingCarrier($params['losing_carrier'] ?? null),
                ),
        ];

        foreach (['notification_emails', 'target_port_in_date', 'target_port_in_time_range_start', 'target_port_in_time_range_end', 'bundle_sid'] as $key) {
            if (array_key_exists($key, $params)) {
                $payload[$key] = $params[$key];
            }
        }

        $created = $this->connectionClient($connection)
            ->numbers
            ->v1
            ->portingPortIns
            ->create(PortingPortInModels::createNumbersV1PortingPortInCreate($payload));

        $status = (string) ($created->portInRequestStatus ?? 'waiting_for_signature');

        return [
            'sid' => (string) $created->portInRequestSid,
            'status' => self::normalizePortStatus($status),
        ];
    }

    public function trustStatus(PhoneConnection $connection): array
    {
        $out = [
            'customer_profile' => 'pending',
            'shaken_stir' => 'pending',
            'cnam' => 'pending',
            'voice_integrity' => 'pending',
        ];

        $client = $this->connectionClient($connection);

        foreach ($client->trusthub->v1->customerProfiles->read([], 20) as $profile) {
            $status = self::normalizeTrustStatus((string) $profile->status);
            $out['customer_profile'] = $status;
            break;
        }

        foreach ($client->trusthub->v1->trustProducts->read([], 20) as $product) {
            $name = strtolower((string) ($product->friendlyName ?? ''));
            $status = self::normalizeTrustStatus((string) $product->status);
            if (str_contains($name, 'shaken') || str_contains($name, 'stir')) {
                $out['shaken_stir'] = $status;
            } elseif (str_contains($name, 'cnam')) {
                $out['cnam'] = $status;
            } elseif (str_contains($name, 'voice integrity') || str_contains($name, 'integrity')) {
                $out['voice_integrity'] = $status;
            }
        }

        return $out;
    }

    public function submitTrust(PhoneConnection $connection, array $payload): array
    {
        $email = (string) ($payload['email'] ?? '');
        $friendly = (string) ($payload['friendly_name'] ?? 'EzBizi Trust');
        $policySid = (string) ($payload['policy_sid'] ?? '');

        // Same as Fake: incomplete payload returns pending — do not throw or
        // call Twilio while Settings is still collecting email / policy_sid.
        if ($email === '' || $policySid === '') {
            return [
                'customer_profile' => 'pending',
                'shaken_stir' => 'pending',
                'cnam' => 'pending',
                'voice_integrity' => 'pending',
            ];
        }

        $client = $this->connectionClient($connection);
        $profiles = $client->trusthub->v1->customerProfiles->read([], 1);
        if ($profiles === []) {
            $client->trusthub->v1->customerProfiles->create($friendly, $email, $policySid);
        } else {
            $client->trusthub->v1->customerProfiles($profiles[0]->sid)->update([
                'status' => 'pending-review',
                'email' => $email,
            ]);
        }

        return $this->trustStatus($connection);
    }

    /** Map Twilio port labels / webhook values to our snake_case column. */
    public static function normalizePortStatus(string $status): string
    {
        $key = strtolower(str_replace([' ', '-'], '_', trim($status)));

        return match ($key) {
            'in_review', 'inreview' => 'in_review',
            'waiting_for_signature', 'waitingforsignature' => 'waiting_for_signature',
            'in_progress', 'inprogress' => 'in_progress',
            'action_required', 'actionrequired' => 'action_required',
            'completed', 'complete' => 'completed',
            'canceling', 'cancelling' => 'canceling',
            'canceled', 'cancelled' => 'canceled',
            default => $key !== '' ? $key : 'waiting_for_signature',
        };
    }

    public static function normalizeTrustStatus(string $status): string
    {
        $key = strtolower(str_replace([' ', '_'], '-', trim($status)));

        return match ($key) {
            'draft' => 'draft',
            'pending-review', 'in-review', 'pending' => 'pending',
            'twilio-approved', 'approved' => 'twilio-approved',
            'twilio-rejected', 'rejected' => 'twilio-rejected',
            default => $key !== '' ? $key : 'pending',
        };
    }

    public function recordingMedia(PhoneConnection $connection, string $recordingSid): string
    {
        $recording = $this->connectionClient($connection)
            ->recordings($recordingSid)
            ->fetch();

        $uri = $recording->uri;
        $mediaUrl = 'https://api.twilio.com'.preg_replace('/\.json$/', '.mp3', $uri);

        $accountSid = (string) $connection->account_sid;
        $apiKeySid = (string) $connection->api_key_sid;
        $secret = (string) $connection->credentials;

        $context = stream_context_create([
            'http' => [
                'header' => 'Authorization: Basic '.base64_encode($apiKeySid.':'.$secret),
            ],
        ]);

        $bytes = @file_get_contents($mediaUrl, false, $context);
        if ($bytes === false) {
            throw new RuntimeException('Twilio recording media could not be fetched.');
        }

        return $bytes;
    }

    public function deleteRecording(PhoneConnection $connection, string $recordingSid): void
    {
        $this->connectionClient($connection)
            ->recordings($recordingSid)
            ->delete();
    }

    public function clearTwimlApp(PhoneConnection $connection): void
    {
        $sid = (string) $connection->twiml_app_sid;
        if ($sid === '') {
            return;
        }

        $this->connectionClient($connection)
            ->applications($sid)
            ->update([
                'voiceUrl' => '',
                'statusCallback' => '',
            ]);
    }

    public function closeSubaccount(PhoneConnection $connection): void
    {
        $this->platformClient()
            ->api
            ->v2010
            ->accounts((string) $connection->account_sid)
            ->update(['status' => 'closed']);
    }

    /**
     * A client that IS this connection's account, for Notify.
     *
     * ── Why not platformClientForAccount, which every neighbour uses ──
     *
     * That helper authenticates as the parent and passes the subaccount SID,
     * which works because the classic API puts the account in the PATH:
     * `api.twilio.com/2010-04-01/Accounts/{sid}/…`. Notify does not. Its URL is
     * `notify.twilio.com/v1/Credentials` with no account segment at all, so the
     * owning account is decided entirely by who authenticates — and the SID
     * argument is silently ignored.
     *
     * I used it anyway on 2026-09-09, with a comment saying the credential was
     * created against the subaccount. Three credentials were created on the
     * PARENT, the command reported three successes with real CR SIDs, and the
     * columns were filled in with SIDs that a token minted from a subaccount
     * cannot use. Nothing failed. That is the exact shape this module keeps
     * producing, and I wrote it into the fix for it.
     *
     * The account's own auth token first, its API key second — the same order
     * and the same reason as [platformClient]: token auth is the one Twilio
     * never withholds anything from.
     */
    private function pushCredentialClient(PhoneConnection $connection): Client
    {
        $accountSid = (string) $connection->account_sid;
        $authToken = (string) $connection->auth_token;

        if ($authToken !== '') {
            return new Client($accountSid, $authToken, $accountSid);
        }

        $apiKeySid = (string) $connection->api_key_sid;
        $apiSecret = (string) $connection->credentials;

        if ($apiKeySid === '' || $apiSecret === '') {
            throw new RuntimeException(
                'This phone connection has no credentials of its own, so a push '
                .'credential created for it would belong to the wrong account.'
            );
        }

        return $this->client($accountSid, $apiKeySid, $apiSecret);
    }

    private function connectionClient(PhoneConnection $connection): Client
    {
        return $this->client(
            (string) $connection->account_sid,
            (string) $connection->api_key_sid,
            (string) $connection->credentials,
        );
    }

    private function client(string $accountSid, string $apiKeySid, string $apiSecret): Client
    {
        return new Client($apiKeySid, $apiSecret, $accountSid);
    }

    /**
     * The parent account, authenticated the strongest way available.
     *
     * Account SID + auth token when we have it, API key otherwise. The
     * difference is not cosmetic: Twilio withholds `auth_token` from an
     * account resource under key auth, so creating a subaccount with a key
     * yields an account we cannot validate webhooks for — and §5 refuses a
     * global token, so there is nothing to fall back on.
     */
    private function platformClient(): Client
    {
        [$accountSid, $apiKeySid, $apiSecret] = $this->platformCredentials();
        $authToken = config('services.twilio.platform_auth_token');

        if (is_string($authToken) && $authToken !== '') {
            return new Client($accountSid, $authToken);
        }

        return new Client($apiKeySid, $apiSecret, $accountSid);
    }

    /**
     * API key minted against a subaccount, authenticated as the platform parent.
     */
    private function platformClientForAccount(string $accountSid): Client
    {
        [$platformSid, $apiKeySid, $apiSecret] = $this->platformCredentials();
        $authToken = config('services.twilio.platform_auth_token');

        // Acting AS the subaccount, authenticated as the parent. Key auth can
        // create here but cannot read or delete, which is why the token is
        // preferred wherever it exists.
        if (is_string($authToken) && $authToken !== '') {
            return new Client($platformSid, $authToken, $accountSid);
        }

        return new Client($apiKeySid, $apiSecret, $accountSid);
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function platformCredentials(): array
    {
        $accountSid = config('services.twilio.platform_account_sid');
        $apiKeySid = config('services.twilio.platform_api_key_sid');
        $apiSecret = config('services.twilio.platform_api_secret');

        if (! is_string($accountSid) || $accountSid === ''
            || ! is_string($apiKeySid) || $apiKeySid === ''
            || ! is_string($apiSecret) || $apiSecret === '') {
            throw new RuntimeException(
                'EzBizi has not been given a Twilio account to create phone service with.'
            );
        }

        return [$accountSid, $apiKeySid, $apiSecret];
    }
}
