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
     * @return array{account_sid: string, friendly_name: ?string, auth_token: ?string}
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
            'auth_token' => $account->authToken,
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
        $normalized = self::normalizeSearchCriteria($criteria);
        $country = $normalized['country'] ?? 'US';
        unset($normalized['country']);

        $page = $this->connectionClient($connection)
            ->availablePhoneNumbers($country)
            ->local
            ->read($normalized, 20);

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
            'in_lata' => 'inLata',
            'in_locality' => 'inLocality',
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

    public function startPort(PhoneConnection $connection, array $e164s, array $params = []): array
    {
        $phoneNumbers = array_map(
            fn (string $e164) => \Twilio\Rest\Numbers\V1\PortingPortInModels::createNumbersV1PortingPortInCreatePhoneNumbers([
                'phone_number' => $e164,
            ]),
            $e164s,
        );

        $payload = [
            'account_sid' => (string) $connection->account_sid,
            'phone_numbers' => $phoneNumbers,
            'documents' => $params['documents'] ?? [],
            'losing_carrier_information' => $params['losing_carrier_information']
                ?? \Twilio\Rest\Numbers\V1\PortingPortInModels::createNumbersV1PortingLosingCarrierInformation(
                    is_array($params['losing_carrier'] ?? null) ? $params['losing_carrier'] : [],
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
            ->create(\Twilio\Rest\Numbers\V1\PortingPortInModels::createNumbersV1PortingPortInCreate($payload));

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
