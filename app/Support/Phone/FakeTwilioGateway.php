<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use RuntimeException;
use Twilio\Exceptions\RestException;

/**
 * In-memory Twilio for tests. Records every call; never hits the network.
 */
final class FakeTwilioGateway implements TwilioGateway
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    public bool $lookupEnabled = true;

    /** When true, lookup throws a miss (inbound must not 500). */
    public bool $lookupMiss = false;

    /** When set, createTwimlApp throws this message (connect-order tests). */
    public ?string $failCreateTwimlAppWith = null;

    /** When set, sendMessage throws this message (number.broken tests). */
    public ?string $failSendMessageWith = null;

    /**
     * When set (and failSendMessageWith is null), sendMessage throws a Twilio
     * RestException with this error code — e.g. 21211 invalid To.
     */
    public ?int $failSendMessageCode = null;

    public function createSubaccount(string $friendlyName): array
    {
        $this->record(__FUNCTION__, func_get_args());

        // Mirrors the live gateway: an existing account of the same name is
        // reused, never duplicated. Two "A-1 Chimney" subaccounts appeared in
        // production because a failure downstream left the created account
        // behind and the customer pressed the button again.
        foreach ($this->subaccounts as $existing) {
            // Live only — a closed account keeps its name and refuses writes.
            if (($existing['status'] ?? 'active') !== 'active') {
                continue;
            }

            if (strcasecmp(trim($existing['friendly_name']), trim($friendlyName)) === 0) {
                return [
                    'account_sid' => $existing['account_sid'],
                    'auth_token' => $this->adoptableAuthToken,
                ];
            }
        }

        $created = [
            'account_sid' => 'AC'.bin2hex(random_bytes(16)),
            'friendly_name' => $friendlyName,
            'status' => 'active',
        ];
        $this->subaccounts[] = $created;

        return [
            'account_sid' => $created['account_sid'],
            'auth_token' => $this->subaccountReturnsNoAuthToken
                ? ''
                : bin2hex(random_bytes(16)),
        ];
    }

    /**
     * Subaccounts a test can pretend already exist. Empty by default, so a test
     * that never sets them sees the state a fresh platform account is in.
     *
     * @var list<array{account_sid: string, friendly_name: string, status: string}>
     */
    public array $subaccounts = [];

    /** Auth token [adoptSubaccount] will accept. Anything else is refused. */
    public string $adoptableAuthToken = 'the-real-auth-token';

    /**
     * Reproduce the production failure: Twilio creates the subaccount but
     * returns no auth token, which is what key auth does. The controller then
     * refuses AFTER a real account exists.
     */
    public bool $subaccountReturnsNoAuthToken = false;

    public function listSubaccounts(): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return $this->subaccounts;
    }

    public function adoptSubaccount(string $accountSid, string $authToken): array
    {
        $this->record(__FUNCTION__, func_get_args());

        $owned = false;
        foreach ($this->subaccounts as $sub) {
            if ($sub['account_sid'] === $accountSid) {
                $owned = true;
                break;
            }
        }

        if (! $owned) {
            throw new RuntimeException(
                'That phone account is not one EzBizi manages. Connect it as your own Twilio instead.'
            );
        }

        if ($authToken !== $this->adoptableAuthToken) {
            throw new RuntimeException(
                'That auth token does not match this phone account. Copy it from the account it belongs to.'
            );
        }

        return [
            'account_sid' => $accountSid,
            'api_key_sid' => 'SK'.bin2hex(random_bytes(16)),
            'api_secret' => bin2hex(random_bytes(16)),
            'auth_token' => $authToken,
        ];
    }

    public function createApiKey(string $accountSid, string $friendlyName = 'EzBizi'): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return [
            'sid' => 'SK'.bin2hex(random_bytes(16)),
            'secret' => bin2hex(random_bytes(16)),
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
        $this->record(__FUNCTION__, func_get_args());

        if ($this->failCreateTwimlAppWith !== null) {
            throw new RuntimeException($this->failCreateTwimlAppWith);
        }

        return ['sid' => 'AP'.bin2hex(random_bytes(16))];
    }

    public function searchAvailable(PhoneConnection $connection, array $criteria): array
    {
        $normalized = HttpTwilioGateway::normalizeSearchCriteria($criteria);
        $this->record(__FUNCTION__, [$connection->id, $normalized]);

        if ($this->failSearchAvailableWith !== null) {
            throw new RuntimeException($this->failSearchAvailableWith);
        }

        if ($this->available !== null) {
            return $this->available;
        }

        $area = $normalized['areaCode'] ?? '555';
        $tollFree = ($criteria['type'] ?? 'local') === 'toll_free';

        return [[
            'phone_number' => $tollFree ? '+18330101001' : '+1'.$area.'0101001',
            'friendly_name' => 'Fake available',
            'locality' => 'Testville',
            'region' => 'TS',
            'postal_code' => '00001',
            'iso_country' => strtoupper((string) ($criteria['country'] ?? 'US')),
            'lata' => '822',
            'rate_center' => 'TESTVILLE',
            'latitude' => 27.0,
            'longitude' => -80.0,
            'address_requirements' => 'none',
            'beta' => false,
            'capabilities' => ['voice' => true, 'sms' => true, 'mms' => true, 'fax' => false],
        ]];
    }

    /**
     * Search hits a test wants to force — an empty list, an address-required
     * number, a toll-free run. Null means the generated single hit above.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $available = null;

    /** Make the search throw, so the unreachable-Twilio path can be proved. */
    public ?string $failSearchAvailableWith = null;

    /** @var list<array{country_code: string, country: string}> */
    public array $countries = [
        ['country_code' => 'US', 'country' => 'United States'],
        ['country_code' => 'CA', 'country' => 'Canada'],
    ];

    /**
     * Usage rows a test wants returned. Null gives one calls row and one sms
     * row, which is enough to prove the importer sums and upserts.
     *
     * @var list<array<string, mixed>>|null
     */
    public ?array $usage = null;

    public ?string $failUsageDailyWith = null;

    public function usageDaily(PhoneConnection $connection, string $startDate, string $endDate): array
    {
        $this->record(__FUNCTION__, [$connection->id, $startDate, $endDate]);

        if ($this->failUsageDailyWith !== null) {
            throw new RuntimeException($this->failUsageDailyWith);
        }

        if ($this->usage !== null) {
            return $this->usage;
        }

        return [
            [
                'category' => 'calls',
                'description' => 'Voice',
                'start_date' => $startDate,
                'usage' => '12',
                'usage_unit' => 'minutes',
                'count' => '4',
                'count_unit' => 'calls',
                'price' => '0.1700',
                'price_unit' => 'USD',
            ],
            [
                'category' => 'sms',
                'description' => 'SMS',
                'start_date' => $startDate,
                'usage' => '3',
                'usage_unit' => 'messages',
                'count' => '3',
                'count_unit' => 'messages',
                'price' => '0.0237',
                'price_unit' => 'USD',
            ],
        ];
    }

    public function availableCountries(PhoneConnection $connection): array
    {
        $this->record(__FUNCTION__, [$connection->id]);

        return $this->countries;
    }

    /**
     * Live per-number state a test wants to force, keyed by Twilio SID.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $incomingDetail = [];

    public function fetchIncoming(PhoneConnection $connection, string $incomingSid): array
    {
        $this->record(__FUNCTION__, [$connection->id, $incomingSid]);

        return $this->incomingDetail[$incomingSid] ?? [
            'sid' => $incomingSid,
            'phone_number' => '+15550101001',
            'friendly_name' => 'Fake line',
            'capabilities' => ['voice' => true, 'sms' => true, 'mms' => true, 'fax' => false],
            'emergency_status' => 'Inactive',
            'emergency_address_sid' => null,
            'voice_caller_id_lookup' => false,
            'address_requirements' => 'none',
        ];
    }

    /** @var list<array<string, mixed>> */
    public array $addresses = [];

    public function listAddresses(PhoneConnection $connection): array
    {
        $this->record(__FUNCTION__, [$connection->id]);

        return $this->addresses;
    }

    public function createAddress(PhoneConnection $connection, array $address): array
    {
        $this->record(__FUNCTION__, [$connection->id, $address]);

        $sid = 'AD'.bin2hex(random_bytes(16));

        $this->addresses[] = [
            'sid' => $sid,
            'friendly_name' => $address['friendly_name'] ?? null,
            'customer_name' => (string) $address['customer_name'],
            'street' => (string) $address['street'],
            'city' => (string) $address['city'],
            'region' => (string) $address['region'],
            'postal_code' => (string) $address['postal_code'],
            'iso_country' => (string) $address['iso_country'],
            'emergency_enabled' => true,
            'validated' => true,
        ];

        return ['sid' => $sid, 'validated' => true, 'emergency_enabled' => true];
    }

    /**
     * Numbers a test can pretend the adopted account already holds.
     *
     * @var list<array{sid: string, phone_number: string, friendly_name: string, voice_url: string, sms_url: string}>
     */
    public array $incoming = [];

    public function listIncoming(PhoneConnection $connection): array
    {
        $this->record(__FUNCTION__, [$connection->id]);

        return $this->incoming;
    }

    /** @var array<string, list<array{sid: string, phone_number: string, friendly_name: string, voice_url: string, sms_url: string}>> */
    public array $accountIncoming = [];

    public function listAccountIncoming(string $accountSid): array
    {
        $this->record(__FUNCTION__, [$accountSid]);

        return $this->accountIncoming[$accountSid] ?? [];
    }

    public function purchaseIncoming(PhoneConnection $connection, string $phoneNumber, array $params = []): array
    {
        $this->record(__FUNCTION__, [$connection->id, $phoneNumber, $params]);

        return [
            'sid' => 'PN'.bin2hex(random_bytes(16)),
            'phone_number' => $phoneNumber,
        ];
    }

    public function updateIncoming(PhoneConnection $connection, string $incomingSid, array $params): void
    {
        $this->record(__FUNCTION__, [$connection->id, $incomingSid, $params]);
    }

    public ?string $failMoveIncomingWith = null;

    public function moveIncomingToAccount(
        PhoneConnection $from,
        string $incomingSid,
        string $targetAccountSid,
        array $params = [],
    ): void {
        $this->record(__FUNCTION__, [$from->id, $incomingSid, $targetAccountSid, $params]);

        if ($this->failMoveIncomingWith !== null) {
            throw new RuntimeException($this->failMoveIncomingWith);
        }
    }

    public function releaseIncoming(PhoneConnection $connection, string $incomingSid): void
    {
        $this->record(__FUNCTION__, [$connection->id, $incomingSid]);
    }

    public function createPushCredential(
        PhoneConnection $connection,
        string $platform,
        array $material,
    ): string {
        $this->record(__FUNCTION__, [$connection->id, $platform, array_keys($material)]);

        return 'CR'.substr(md5($connection->id.$platform), 0, 32);
    }

    public function accessToken(PhoneConnection $connection, string $identity, ?string $platform = null): string
    {
        $this->record(__FUNCTION__, [$connection->id, $identity, $platform]);

        return 'fake-access-token.'.$identity.'.'.$connection->id;
    }

    /** When set, createCall throws this message. */
    public ?string $failCreateCallWith = null;

    /**
     * Queue sizes a test can set, keyed by queue friendly name.
     *
     * Empty by default, which is the honest default: a group that has never
     * held anybody has no queue on the account either.
     *
     * @var array<string, array{waiting: int, average_wait: int}>
     */
    public array $queues = [];

    /**
     * Set to make queueSizes() throw, so a test can be Twilio being down.
     *
     * "Nobody is waiting" and "we could not ask" are different facts and the
     * endpoint has to tell them apart; without a way to fail here, only the
     * happy half is reachable.
     */
    public ?string $failQueueSizesWith = null;

    public function queueSizes(PhoneConnection $connection): array
    {
        $this->record(__FUNCTION__, [$connection->id]);

        if ($this->failQueueSizesWith !== null) {
            throw new RuntimeException($this->failQueueSizesWith);
        }

        return $this->queues;
    }

    public function createCall(
        PhoneConnection $connection,
        string $from,
        string $to,
        string $twiml,
    ): array {
        $this->record(__FUNCTION__, [$connection->id, $from, $to, $twiml]);

        if ($this->failCreateCallWith !== null) {
            throw new RuntimeException($this->failCreateCallWith);
        }

        return [
            'sid' => 'CA'.bin2hex(random_bytes(16)),
            'status' => 'queued',
        ];
    }

    /** Set to make a redirect fail, the way an ended call does. */
    public ?string $failRedirectWith = null;

    public function redirectCall(PhoneConnection $connection, string $callSid, string $twiml): void
    {
        $this->record(__FUNCTION__, [$connection->id, $callSid, $twiml]);

        if ($this->failRedirectWith !== null) {
            throw new RuntimeException($this->failRedirectWith);
        }
    }

    /**
     * The legs a test says Twilio is ringing, keyed by parent call sid.
     *
     * @var array<string, list<array{sid: string, to: string, status: string}>>
     */
    public array $legs = [];

    public function childLegs(PhoneConnection $connection, string $parentCallSid): array
    {
        $this->record(__FUNCTION__, [$connection->id, $parentCallSid]);

        return $this->legs[$parentCallSid] ?? [];
    }

    public function endCall(PhoneConnection $connection, string $callSid): void
    {
        $this->record(__FUNCTION__, [$connection->id, $callSid]);
    }

    public function sendPlatformMessage(string $accountSid, string $from, string $to, string $body): array
    {
        $this->record(__FUNCTION__, [$accountSid, $from, $to, $body]);

        return ['sid' => 'SM'.bin2hex(random_bytes(16))];
    }

    public function sendMessage(PhoneConnection $connection, string $from, string $to, string $body, ?string $accountSid = null): array
    {
        $this->record(__FUNCTION__, array_merge(
            [$connection->id, $from, $to, $body],
            $accountSid === null ? [] : [$accountSid],
        ));

        if ($this->failSendMessageWith !== null) {
            throw new RuntimeException($this->failSendMessageWith);
        }

        if ($this->failSendMessageCode !== null) {
            throw new RestException(
                'The To phone number is not a valid phone number.',
                $this->failSendMessageCode,
                400,
            );
        }

        return [
            'sid' => 'SM'.bin2hex(random_bytes(16)),
            'status' => 'queued',
        ];
    }

    /**
     * A detailed lookup a test wants to force — an invalid number, a landline,
     * a mobile. Null gives a valid US mobile.
     *
     * @var array<string, mixed>|null
     */
    public ?array $detailedLookup = null;

    public function lookupDetailed(string $e164): array
    {
        $this->record(__FUNCTION__, func_get_args());

        if (! $this->lookupEnabled) {
            throw new RuntimeException('not enabled');
        }

        if ($this->detailedLookup !== null) {
            return $this->detailedLookup;
        }

        return [
            'phone_number' => $e164,
            'valid' => true,
            'national_format' => '(561) 555-0123',
            'country_code' => 'US',
            'line_type' => 'mobile',
            'carrier_name' => 'Fake Wireless',
            'caller_name' => 'Fake Caller',
        ];
    }

    public function lookup(string $e164): array
    {
        $this->record(__FUNCTION__, func_get_args());

        if (! $this->lookupEnabled) {
            throw new RuntimeException('not enabled');
        }

        if ($this->lookupMiss) {
            throw new RuntimeException('lookup miss');
        }

        return [
            'phone_number' => $e164,
            'caller_name' => 'Fake Caller',
        ];
    }

    public function startPort(PhoneConnection $connection, array $e164s, array $params = []): array
    {
        $this->record(__FUNCTION__, [$connection->id, $e164s, $params]);

        return [
            'sid' => 'PW'.bin2hex(random_bytes(16)),
            'status' => 'waiting_for_signature',
        ];
    }

    public function trustStatus(PhoneConnection $connection): array
    {
        $this->record(__FUNCTION__, [$connection->id]);

        return [
            'customer_profile' => 'pending',
            'shaken_stir' => 'pending',
            'cnam' => 'pending',
            'voice_integrity' => 'pending',
        ];
    }

    public function submitTrust(PhoneConnection $connection, array $payload): array
    {
        $this->record(__FUNCTION__, [$connection->id, $payload]);

        return [
            'customer_profile' => 'pending',
            'shaken_stir' => 'pending',
            'cnam' => 'pending',
            'voice_integrity' => 'pending',
        ];
    }

    public function recordingMedia(PhoneConnection $connection, string $recordingSid): string
    {
        $this->record(__FUNCTION__, [$connection->id, $recordingSid]);

        return 'fake-recording-bytes';
    }

    /** Recording SIDs whose delete fails, as a Twilio 500 would. */
    public array $failDeleteRecordingFor = [];

    public function deleteRecording(PhoneConnection $connection, string $recordingSid): void
    {
        $this->record(__FUNCTION__, [$connection->id, $recordingSid]);

        if (in_array($recordingSid, $this->failDeleteRecordingFor, true)) {
            throw new RestException('Twilio is down.', 20500, 500);
        }
    }

    public function clearTwimlApp(PhoneConnection $connection): void
    {
        $this->record(__FUNCTION__, [$connection->id, $connection->twiml_app_sid]);
    }

    public function closeSubaccount(PhoneConnection $connection): void
    {
        $this->record(__FUNCTION__, [$connection->id, $connection->account_sid]);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
