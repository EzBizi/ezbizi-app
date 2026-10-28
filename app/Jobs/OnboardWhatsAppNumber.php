<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Support\Social\FacebookOAuth;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Make a business's own WhatsApp number, just connected by Embedded Signup,
 * actually send and receive.
 *
 * Connecting hands us a token and nothing else. Three things still stand
 * between that and a working line, the same shape SubscribeMetaMessages fills
 * for a Page:
 *
 *  1. the WABA subscribes our app, or Meta never calls our webhook for it;
 *  2. the number is registered on the Cloud API (with a two-step PIN we
 *     invent and keep encrypted on the connection, so it can be registered
 *     again later without asking the business);
 *  3. a whatsapp_numbers row names the tenant. The webhook finds the
 *     organization through that row and only through it, and WhatsAppCloud
 *     sends from it - so without it inbound goes nowhere and sends refuse.
 *
 * Every step is safe to repeat: subscribing twice is a no-op at Meta, "already
 * registered" is success, and the row is an upsert on phone_number_id.
 */
final class OnboardWhatsAppNumber implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    private const TIMEOUT = 15;

    public function __construct(
        public readonly string $connectionId,
        public readonly string $wabaId,
    ) {}

    public function handle(): void
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()->find($this->connectionId);
        $token = $connection?->credentials['access_token'] ?? null;

        if ($connection === null || $connection->provider !== SocialProvider::WhatsApp->value
            || ! is_string($token) || $token === '') {
            return; // Disconnected between the connect and this running.
        }

        $phoneNumberId = (string) $connection->remote_id;
        $graph = 'https://graph.facebook.com/'.FacebookOAuth::GRAPH_VERSION;

        // A number EzBizi already routes to ANOTHER organization is not this
        // tenant's to take over: refused loudly rather than re-pointed, which
        // would silently move somebody else's customer conversations.
        $existing = WhatsAppNumber::query()->withoutGlobalScopes()->where('phone_number_id', $phoneNumberId)->first();

        if ($existing !== null && $existing->organization_id !== $connection->organization_id) {
            throw new RuntimeException('WhatsApp number '.$phoneNumberId.' already belongs to another organization.');
        }

        $this->ok(Http::withToken($token)->timeout(self::TIMEOUT)
            ->post($graph.'/'.$this->wabaId.'/subscribed_apps'), 'subscribe WABA '.$this->wabaId);

        $credentials = (array) $connection->credentials;
        $pin = $credentials['pin'] ?? null;

        if (! is_string($pin) || ! preg_match('/^\d{6}$/', $pin)) {
            $pin = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            // Saved BEFORE registering: a register that succeeds and a save
            // that never happens would leave a PIN nobody knows on the number.
            $connection->forceFill(['credentials' => [...$credentials, 'pin' => $pin]])->save();
        }

        $info = Http::withToken($token)->timeout(self::TIMEOUT)
            ->get($graph.'/'.$phoneNumberId, ['fields' => 'display_phone_number,verified_name,is_on_biz_app']);

        if ($info->json('is_on_biz_app') === true) {
            // Coexistence: the number stays in the business's WhatsApp app and
            // is never registered (that would take it off the phone). Meta
            // allows the contacts and history sync once, within 24 hours.
            foreach (['smb_app_state_sync', 'history'] as $sync) {
                $this->ok(Http::withToken($token)->timeout(self::TIMEOUT)
                    ->post($graph.'/'.$phoneNumberId.'/smb_app_data', ['messaging_product' => 'whatsapp', 'sync_type' => $sync]), $sync.' '.$phoneNumberId);
            }
        } else {
            $register = Http::withToken($token)->timeout(self::TIMEOUT)
                ->post($graph.'/'.$phoneNumberId.'/register', ['messaging_product' => 'whatsapp', 'pin' => $pin]);

            // ponytail: matched on Meta's wording; no stable error code is documented for it.
            if (! str_contains(strtolower((string) $register->json('error.message')), 'already registered')) {
                $this->ok($register, 'register number '.$phoneNumberId);
            }
        }
        $digits = preg_replace('/\D+/', '', (string) ($info->json('display_phone_number') ?: $connection->handle)) ?? '';

        app(TenantContext::class)->run(
            Organization::findOrFail($connection->organization_id),
            fn () => WhatsAppNumber::query()->updateOrCreate(
                ['phone_number_id' => $phoneNumberId],
                [
                    'organization_id' => $connection->organization_id,
                    'waba_id' => $this->wabaId,
                    'display_number' => $digits === '' ? null : '+'.$digits,
                    'display_name' => $info->json('verified_name') ?: null,
                    'social_connection_id' => $connection->getKey(),
                ],
            ),
        );
    }

    private function ok(Response $response, string $what): void
    {
        if ($response->failed() || $response->json('success') === false) {
            // Thrown so the queue retries, and the last failure is kept.
            throw new RuntimeException('Meta refused to '.$what.': '
                .($response->json('error.message') ?? 'HTTP '.$response->status()));
        }
    }
}
