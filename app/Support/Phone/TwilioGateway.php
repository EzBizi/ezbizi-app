<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use RuntimeException;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
use Twilio\Rest\Client;

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
     * @return array{account_sid: string, friendly_name: ?string}
     */
    public function verifyAccount(string $accountSid, string $apiKeySid, string $apiSecret): array;

    /**
     * @return array{account_sid: string, auth_token: ?string}
     */
    public function createSubaccount(string $friendlyName): array;

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
     * @param  array{area_code?: string, contains?: string, voice?: bool, sms?: bool}  $criteria
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

    /** Raw recording media bytes. */
    public function recordingMedia(PhoneConnection $connection, string $recordingSid): string;

    public function deleteRecording(PhoneConnection $connection, string $recordingSid): void;
}

/**
 * Live Twilio REST / JWT. Bound outside tests.
 */
final class HttpTwilioGateway implements TwilioGateway
{
    public function verifyAccount(string $accountSid, string $apiKeySid, string $apiSecret): array
    {
        $account = $this->client($accountSid, $apiKeySid, $apiSecret)
            ->api
            ->v2010
            ->accounts($accountSid)
            ->fetch();

        return [
            'account_sid' => $account->sid,
            'friendly_name' => $account->friendlyName,
        ];
    }

    public function createSubaccount(string $friendlyName): array
    {
        $account = $this->platformClient()->api->v2010->accounts->create([
            'friendlyName' => $friendlyName,
        ]);

        return [
            'account_sid' => $account->sid,
            'auth_token' => $account->authToken,
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
        $country = $criteria['country'] ?? 'US';
        unset($criteria['country']);

        $page = $this->connectionClient($connection)
            ->availablePhoneNumbers($country)
            ->local
            ->read($criteria, 20);

        $out = [];
        foreach ($page as $number) {
            $out[] = [
                'phone_number' => $number->phoneNumber,
                'friendly_name' => $number->friendlyName,
                'locality' => $number->locality,
                'region' => $number->region,
            ];
        }

        return $out;
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

    public function releaseIncoming(PhoneConnection $connection, string $incomingSid): void
    {
        $this->connectionClient($connection)
            ->incomingPhoneNumbers($incomingSid)
            ->delete();
    }

    public function accessToken(PhoneConnection $connection, string $identity): string
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
            3600,
            $identity,
        );

        $grant = new VoiceGrant;
        $grant->setIncomingAllow(true);
        if ($connection->twiml_app_sid) {
            $grant->setOutgoingApplicationSid((string) $connection->twiml_app_sid);
        }
        $token->addGrant($grant);

        return $token->toJWT();
    }

    public function sendMessage(PhoneConnection $connection, string $from, string $to, string $body): array
    {
        $message = $this->connectionClient($connection)->messages->create($to, [
            'from' => $from,
            'body' => $body,
        ]);

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

    private function platformClient(): Client
    {
        [$accountSid, $apiKeySid, $apiSecret] = $this->platformCredentials();

        return new Client($apiKeySid, $apiSecret, $accountSid);
    }

    /**
     * API key minted against a subaccount, authenticated as the platform parent.
     */
    private function platformClientForAccount(string $accountSid): Client
    {
        [, $apiKeySid, $apiSecret] = $this->platformCredentials();

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
