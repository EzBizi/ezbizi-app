<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\ConnectionSetup;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Tell Thumbtack where to send this business's enquiries.
 *
 * ── Why connecting is not enough on its own ──────────────────────
 *
 * Thumbtack's supply API cannot list negotiations. A connected account with no
 * registered webhook is not a slow account — it is a silent one, permanently,
 * because there is no endpoint that would ever find the enquiries it missed.
 *
 * That is exactly what shipped on 2026-09-08: twelve businesses connected, the
 * screen said Connected, and nothing was ever going to arrive. `ensure()`
 * existed and had tests; nothing called it.
 *
 * ── Why a job and not a line in the callback ─────────────────────
 *
 * One `ensure()` is a list, then a create or an update, then a credential
 * write — three round trips to Thumbtack. Jose's first connect returned TWELVE
 * businesses, so doing this inline would have put about thirty-six sequential
 * calls inside the HTTP request that draws the "Connected" screen.
 *
 * And it must not be all-or-nothing. One business whose registration fails —
 * Thumbtack briefly down, a business the token cannot reach — must not stop the
 * other eleven from receiving leads. One job per connection means each succeeds
 * or is retried on its own.
 *
 * ── Retries, unlike the publish job ──────────────────────────────
 *
 * PublishSocialTarget sets `tries = 1` because a retried post can be published
 * twice to a real audience. This is the opposite case: `ensure()` looks before
 * it creates, so running it again repairs rather than duplicates, and the cost
 * of NOT retrying is an account that silently receives nothing. Retried, with
 * backoff.
 */
final class RegisterThumbtackWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 300];

    public function __construct(public readonly string $connectionId) {}

    public function handle(TenantContext $tenant): void
    {
        $connection = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Thumbtack->value)
            ->find($this->connectionId);

        if ($connection === null) {
            return; // Disconnected between the connect and this running.
        }

        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($connection->organization_id);

        if ($organization === null) {
            return;
        }

        $tenant->run($organization, function () use ($connection) {
            // The route, not a configured string. A webhook registered against
            // an address that does not resolve to this installation is the
            // failure that looks like success: Thumbtack accepts it, and the
            // events go somewhere else forever.
            ThumbtackWebhooks::for($connection)->ensure(route('social.thumbtack.webhook'));
        });

        // Its associate phone numbers now, not at 04:40 tomorrow (desk audit,
        // 2026-11-12). After the webhook, so the two never renew the grant at once.
        Artisan::call('ezbizi:sync-thumbtack-phone-numbers', ['--connection' => $this->connectionId]);
    }

    /** The card goes broken with what did not finish, not green over failed_jobs (Jose, 2026-11-12). */
    public function failed(Throwable $e): void
    {
        ConnectionSetup::failed($this->connectionId, 'Thumbtack setup did not finish: its webhook could not be registered, so leads are not arriving. Reconnect Thumbtack.', $e);
    }
}
