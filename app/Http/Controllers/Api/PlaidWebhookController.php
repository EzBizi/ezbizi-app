<?php

namespace App\Http\Controllers\Api;

use App\Jobs\RecheckPlaidItem;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Http\Request;

/**
 * POST /api/v1/integrations/plaid/webhook — Plaid says something changed for
 * a bank login (the `webhook` set on every link token, PlaidOAuth).
 *
 * Unauthenticated by necessity, and deliberately trusted for nothing but
 * WHICH bank login to look at: the body's item_id picks a connection, and
 * what happens next is read back from Plaid with EzBizi's own secret.
 *
 *  - TRANSACTIONS (SYNC_UPDATES_AVAILABLE and the rest): a sync of that bank
 *    (/transactions/sync from our stored cursor).
 *  - ITEM (ERROR, LOGIN_REPAIRED, PENDING_DISCONNECT, USER_PERMISSION_REVOKED...):
 *    the bank login is re-checked with /item/get (RecheckPlaidItem), which
 *    marks the card broken or heals it on Plaid's answer, not the webhook's.
 *
 * So a forged webhook can at worst make EzBizi ask Plaid a question it would
 * have asked at the next scheduled run; it can write nothing, and that is why
 * Plaid's optional JWT (Plaid-Verification) is not needed to keep data right.
 * An item_id EzBizi does not hold is a 200 and nothing else.
 */
class PlaidWebhookController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $item = $request->json('item_id');
        $type = (string) $request->json('webhook_type', '');
        if (! is_string($item) || $item === '' || strlen($item) > 64) {
            return response()->json(['ok' => true]);
        }

        $connection = SocialConnection::withoutGlobalScopes()
            ->where('provider', SocialProvider::Plaid->value)->where('remote_id', $item)->first();
        if ($connection === null) {
            return response()->json(['ok' => true]);
        }

        if ($type === 'TRANSACTIONS' && $connection->status === SocialConnection::STATUS_CONNECTED) {
            QueuedCommand::dispatch(['ezbizi:plaid-sync', ['--connection' => (string) $connection->getKey()]]);
        } elseif ($type === 'ITEM') {
            RecheckPlaidItem::dispatch((string) $connection->getKey());
        }

        return response()->json(['ok' => true]);
    }
}
