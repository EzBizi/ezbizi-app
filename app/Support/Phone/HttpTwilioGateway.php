<?php

namespace App\Support\Phone;

use App\Models\PhoneConnection;
use RuntimeException;
use Throwable;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
use Twilio\Rest\Client;

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
