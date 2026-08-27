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

    public function verifyAccount(string $accountSid, string $apiKeySid, string $apiSecret): array
    {
        $this->record(__FUNCTION__, func_get_args());

        return [
            'account_sid' => $accountSid,
            'friendly_name' => 'Fake account',
            'auth_token' => 'auth-token-from-verify',
        ];
    }

    public function createSubaccount(string $friendlyName): array
    {
        $this->record(__FUNCTION__, func_get_args());

        // Mirrors the live gateway: an existing account of the same name is
        // reused, never duplicated. Two "A-1 Chimney" subaccounts appeared in
        // production because a failure downstream left the created account
        // behind and the customer pressed the button again.
        foreach ($this->subaccounts as $existing) {
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

        $area = $normalized['areaCode'] ?? '555';

        return [[
            'phone_number' => '+1'.$area.'0101001',
            'friendly_name' => 'Fake available',
            'locality' => 'Testville',
            'region' => 'TS',
        ]];
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

    public function releaseIncoming(PhoneConnection $connection, string $incomingSid): void
    {
        $this->record(__FUNCTION__, [$connection->id, $incomingSid]);
    }

    public function accessToken(PhoneConnection $connection, string $identity): string
    {
        $this->record(__FUNCTION__, [$connection->id, $identity]);

        return 'fake-access-token.'.$identity.'.'.$connection->id;
    }

    public function sendMessage(PhoneConnection $connection, string $from, string $to, string $body): array
    {
        $this->record(__FUNCTION__, [$connection->id, $from, $to, $body]);

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

    public function deleteRecording(PhoneConnection $connection, string $recordingSid): void
    {
        $this->record(__FUNCTION__, [$connection->id, $recordingSid]);
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
