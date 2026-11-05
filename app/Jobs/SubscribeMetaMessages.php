<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\MetaLeadForms;
use App\Support\Social\MetaMessages;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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

    /** How far back a reconnect reads what it missed; a longer outage is rare enough to run by hand. */
    private const CATCH_UP_DAYS = 7;

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

        $post = fn (string $fields) => Http::timeout(10)->asForm()->post($url, [
            'subscribed_fields' => $fields,
            'access_token' => $token,
        ]);

        // A Page also delivers lead-ad forms (MetaLeadForms). Asked for with the
        // messages, and dropped if Meta refuses it - a grant without
        // leads_retrieval must still get its Messenger enquiries.
        $response = $connection->provider === SocialProvider::FacebookPage->value
            ? $post($fields.',leadgen')
            : null;

        if ($response === null || $response->failed() || $response->json('success') !== true) {
            $response = $post($fields);
        }

        if ($response->failed() || $response->json('success') !== true) {
            // Thrown so the queue retries, and the last failure is kept.
            throw new RuntimeException('Meta refused the message subscription for '
                .$connection->provider.' '.$connection->remote_id.': '
                .($response->json('error.message') ?? 'HTTP '.$response->status()));
        }

        // A reconnect is a new row: the enquiries the old one brought were
        // hidden with it (connection_id nulled) and come back here (Jose,
        // 2026-10-14: "the facebook messenger leads are not appearing anymore").
        //
        // And what arrived while it was disconnected was never delivered at all:
        // it is read back from Meta (37 Messenger enquiries on 2026-11-05).
        app(TenantContext::class)->run(
            Organization::findOrFail($connection->organization_id),
            function () use ($connection) {
                app(MetaMessages::class)->reclaim($connection);

                // Never a reason to subscribe again: the hourly run asks the same.
                try {
                    app(MetaMessages::class)->catchUp($connection, now()->subDays(self::CATCH_UP_DAYS));

                    if ($connection->provider === SocialProvider::FacebookPage->value) {
                        app(MetaLeadForms::class)->catchUp($connection, now()->subDays(self::CATCH_UP_DAYS));
                    }
                } catch (Throwable $e) {
                    Log::warning('Meta catch-up after a reconnect failed.', ['connection_id' => $connection->getKey(), 'reason' => $e->getMessage()]);
                }
            },
        );
    }
}
