<?php

namespace App\Support\Telegram;

use App\Models\SocialConnection;
use App\Models\TelegramLink;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** What the Integrations card says about a Telegram bot connection. */
final class TelegramBotStatus
{
    /**
     * Telegram's last delivery error for this bot's webhook, or null when it
     * is delivering (or Telegram could not be asked: unknown is not broken).
     * Cached five minutes so the Integrations list is not a Telegram call each.
     */
    public static function webhookError(SocialConnection $connection): ?string
    {
        $credentials = $connection->credentials;
        $token = is_array($credentials) ? (string) ($credentials['bot_token'] ?? '') : '';
        if ($token === '') {
            return 'Reconnect the bot: its token is missing.';
        }

        return Cache::remember('telegram:webhook-info:'.$connection->getKey(), 300, function () use ($token) {
            try {
                $info = TelegramApi::withToken($token)->call('getWebhookInfo');
            } catch (Throwable) {
                return null;
            }

            if (($info['url'] ?? '') === '') {
                return 'Telegram is not sending this bot\'s messages to EzBizi. Reconnect it.';
            }
            $error = trim((string) ($info['last_error_message'] ?? ''));

            return $error === '' ? null : 'Telegram could not deliver messages: '.$error;
        });
    }

    public static function forget(SocialConnection $connection): void
    {
        Cache::forget('telegram:webhook-info:'.$connection->getKey());
    }

    /** "@a1_tech_bot · 3 staff linked". */
    public static function detail(SocialConnection $connection): string
    {
        $linked = TelegramLink::query()->withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)->count();

        return trim(($connection->handle ? $connection->handle.' · ' : '').($linked === 1 ? '1 staff member linked' : "{$linked} staff linked"));
    }

    /** Best effort: the bot stops calling EzBizi. Never stops a disconnect. */
    public static function deleteWebhook(SocialConnection $connection): void
    {
        $credentials = $connection->credentials;
        $token = is_array($credentials) ? (string) ($credentials['bot_token'] ?? '') : '';
        if ($token === '') {
            return;
        }

        try {
            TelegramApi::withToken($token)->call('deleteWebhook', ['drop_pending_updates' => true]);
        } catch (Throwable) {
        }
        self::forget($connection);
    }
}
