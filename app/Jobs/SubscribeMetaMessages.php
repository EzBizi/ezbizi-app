<?php

namespace App\Jobs;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Tell Meta to deliver a connected Page's or Instagram account's messages to us.
 *
 * Connecting does not do it, for the same reason Thumbtack's does not
 * (RegisterThumbtackWebhook): the app-level webhook is configured once in the
 * Meta console, but Meta only sends a Page's messages once THAT Page has
 * subscribed the app. Without this call a connected Page shows Connected while
 * no Messenger enquiry ever reaches Leads. Found 2026-09-28 preparing App
 * Review - nothing in the codebase had ever called subscribed_apps.
 *
 * Instagram connected through Instagram Login subscribes on its own host,
 * graph.instagram.com, with the account's own token - a Facebook-host call
 * with that token is refused.
 */
final class SubscribeMetaMessages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 300];

    public function __construct(public readonly string $connectionId) {}

    public function handle(): void
    {
        $connection = SocialConnection::query()->withoutGlobalScopes()->find($this->connectionId);
        $token = $connection?->credentials['access_token'] ?? null;

        if ($connection === null || ! is_string($token) || $token === '') {
            return; // Disconnected between the connect and this running.
        }

        [$url, $fields] = match ($connection->provider) {
            SocialProvider::FacebookPage->value => [
                'https://graph.facebook.com/v21.0/'.$connection->remote_id.'/subscribed_apps',
                'messages,messaging_postbacks',
            ],
            SocialProvider::InstagramProfessional->value => [
                'https://graph.instagram.com/v21.0/me/subscribed_apps',
                'messages',
            ],
            default => [null, null],
        };

        if ($url === null) {
            return;
        }

        $response = Http::timeout(10)->asForm()->post($url, [
            'subscribed_fields' => $fields,
            'access_token' => $token,
        ]);

        if ($response->failed() || $response->json('success') !== true) {
            // Thrown so the queue retries, and the last failure is kept.
            throw new RuntimeException('Meta refused the message subscription for '
                .$connection->provider.' '.$connection->remote_id.': '
                .($response->json('error.message') ?? 'HTTP '.$response->status()));
        }
    }
}
