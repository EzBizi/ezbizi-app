<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use RuntimeException;

/**
 * In-memory Twilio for tests. Records every call; never hits the network.
 */
final class FakeTwilioGateway implements TwilioGateway
{
    /** @var list<array{method: string, args: array<int, mixed>}> */
    public array $calls = [];

    public bool $lookupEnabled = true;

    /** When set, createTwimlApp throws this message (connect-order tests). */
    public ?string $failCreateTwimlAppWith = null;

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

        return [
            'account_sid' => 'AC'.bin2hex(random_bytes(16)),
            'auth_token' => bin2hex(random_bytes(16)),
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

        return [
            'phone_number' => $e164,
            'caller_name' => 'Fake Caller',
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
