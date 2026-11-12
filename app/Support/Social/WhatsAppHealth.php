<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use Illuminate\Support\Facades\Http;

/**
 * Is a connected WhatsApp number able to work, line by line (Jose,
 * 2026-11-12: "check if the connection is working properly").
 *
 * Fixes what EzBizi can fix on its own on the way - subscribing the WABA to
 * the app, registering a number that is not kept in the Business app - and
 * says what the person must do for the rest. Read live from Meta every time:
 * a stored verdict is a display that goes stale with no error state.
 */
final class WhatsAppHealth
{
    private const APPROVED_NAMES = ['APPROVED', 'AVAILABLE_WITHOUT_REVIEW'];

    private const SCOPES = ['whatsapp_business_management', 'whatsapp_business_messaging'];

    /** @return array<int, array{key: string, label: string, ok: bool, detail: string, action: ?string}> */
    public static function check(SocialConnection $connection, WhatsAppNumber $number): array
    {
        $token = WhatsAppCloud::tokenFor($number->phone_number_id);
        $graph = WhatsAppCloud::endpoint().'/';
        $meta = fn () => Http::withToken($token)->timeout(20);
        $phone = fn () => $meta()->get($graph.$number->phone_number_id, ['fields' => 'status,is_on_biz_app,verified_name,'
            .'name_status,quality_rating,messaging_limit_tier,health_status'])->json() ?? [];
        $appId = (string) config('services.meta.client_id');
        $subscribed = fn () => collect($meta()->get($graph.$number->waba_id.'/subscribed_apps')->json('data') ?? [])
            ->contains(fn ($app) => (string) ($app['whatsapp_business_api_data']['id'] ?? '') === $appId);

        $p = $phone();
        $pin = $connection->credentials['pin'] ?? null;
        $registeredNow = false;

        if (($p['status'] ?? null) !== 'CONNECTED' && ($p['is_on_biz_app'] ?? false) !== true && is_string($pin)) {
            $meta()->post($graph.$number->phone_number_id.'/register', ['messaging_product' => 'whatsapp', 'pin' => $pin]);
            $p = $phone();
            $registeredNow = ($p['status'] ?? null) === 'CONNECTED';
        }

        $hooked = $subscribed();
        $hookedNow = false;

        if (! $hooked) {
            $meta()->post($graph.$number->waba_id.'/subscribed_apps');
            $hooked = $hookedNow = $subscribed();
        }

        $debug = Http::timeout(20)->get($graph.'debug_token', [
            'input_token' => $token,
            'access_token' => $appId.'|'.config('services.meta.client_secret'),
        ])->json('data') ?? [];
        $scopes = (array) ($debug['scopes'] ?? []);
        $tokenOk = ($debug['is_valid'] ?? false) === true && ($debug['expires_at'] ?? null) === 0
            && array_diff(self::SCOPES, $scopes) === [];

        $health = (array) ($p['health_status'] ?? []);
        $problems = collect($health['entities'] ?? [])->flatMap(fn ($entity) => [
            ...collect($entity['errors'] ?? [])
                // ponytail: 1380xx are WhatsApp calling (SIP) codes, not messaging; dropped by prefix.
                // Upgrade path: read Meta's per-capability field if it starts sending one for messages.
                ->reject(fn ($e) => str_starts_with((string) ($e['error_code'] ?? ''), '1380'))
                ->map(fn ($e) => trim(($e['error_description'] ?? '').' '.($e['possible_solution'] ?? ''))),
            ...($entity['additional_info'] ?? []),
        ])->filter()->unique()->values();
        $sending = (string) ($health['can_send_message'] ?? 'UNKNOWN');
        $nameStatus = (string) ($p['name_status'] ?? 'UNKNOWN');
        $reconnect = 'Disconnect it and connect it again from Settings › Integrations.';

        return [
            [
                'key' => 'registered',
                'label' => 'Registered and connected',
                'ok' => ($p['status'] ?? null) === 'CONNECTED',
                'detail' => ($p['status'] ?? 'Meta did not answer')
                    .(($p['is_on_biz_app'] ?? false) === true ? ', and still in the WhatsApp Business app on the phone' : '')
                    .($registeredNow ? '. EzBizi registered it just now.' : ''),
                'action' => ($p['status'] ?? null) === 'CONNECTED' ? null : $reconnect,
            ],
            [
                'key' => 'display_name',
                'label' => 'Display name approved',
                'ok' => in_array($nameStatus, self::APPROVED_NAMES, true),
                'detail' => '"'.($p['verified_name'] ?? $number->display_name).'": '.$nameStatus,
                'action' => match (true) {
                    in_array($nameStatus, self::APPROVED_NAMES, true) => null,
                    in_array($nameStatus, ['DECLINED', 'EXPIRED'], true) => 'Change the display name in WhatsApp Manager › Phone numbers.',
                    default => 'Nothing to do: Meta reviews the name itself, and the sending limit rises once it approves.',
                },
            ],
            [
                'key' => 'webhook',
                'label' => 'Messages reach EzBizi',
                'ok' => $hooked,
                'detail' => $hooked ? 'EzBizi is subscribed to the account'.($hookedNow ? ' (subscribed just now).' : '.') : 'EzBizi is not subscribed to the account.',
                'action' => $hooked ? null : $reconnect,
            ],
            [
                'key' => 'token',
                'label' => 'Access token',
                'ok' => $tokenOk,
                'detail' => ($debug['type'] ?? 'Unreadable').' token, '
                    .(($debug['expires_at'] ?? null) === 0 ? 'never expires' : 'expires').', '
                    .($scopes === [] ? 'no permissions' : implode(', ', $scopes)),
                'action' => $tokenOk ? null : $reconnect,
            ],
            [
                'key' => 'sending',
                'label' => 'Quality and sending limit',
                'ok' => $sending !== 'BLOCKED',
                'detail' => 'Quality '.($p['quality_rating'] ?? 'UNKNOWN').', limit '.($p['messaging_limit_tier'] ?? 'not set yet')
                    .', sending '.$sending,
                'action' => $problems->implode(' ') ?: null,
            ],
        ];
    }
}
