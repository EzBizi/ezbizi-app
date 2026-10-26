<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Telegram\TelegramApi;
use App\Support\Telegram\TelegramStaffBot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram delivering an update to a staff Tech Assistant bot.
 *
 * Authenticated by X-Telegram-Bot-Api-Secret-Token, which Telegram echoes
 * from setWebhook. The secret IS the tenant: an organization's own connection
 * (Settings › Integrations generates one per bot) is matched first, then the
 * env TELEGRAM_WEBHOOK_SECRET, which names TELEGRAM_ORG_ID. Never anything in
 * the body. Anything else answers 200 so Telegram does not retry.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, TelegramStaffBot $bot): JsonResponse
    {
        $organization = self::organizationFor((string) $request->header('X-Telegram-Bot-Api-Secret-Token'));
        if ($organization === null) {
            return response()->json(['ok' => false], 401);
        }

        $message = $request->input('message');

        if (is_array($message)) {
            try {
                $tenant->run($organization, fn () => $bot->handle($organization, $message));
            } catch (Throwable $e) {
                Log::warning('A Telegram update could not be handled.', ['reason' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** The organization whose bot this secret belongs to, or null. */
    public static function organizationFor(string $secret): ?Organization
    {
        if ($secret === '') {
            return null;
        }

        // One bot per organization, so this is a handful of rows. The secret
        // sits encrypted in credentials, so it is compared after decrypting.
        $connections = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', SocialProvider::Telegram->value)
            ->get();

        foreach ($connections as $connection) {
            $credentials = $connection->credentials;
            $own = is_array($credentials) ? (string) ($credentials['webhook_secret'] ?? '') : '';
            if ($own !== '' && hash_equals($own, $secret)) {
                return Organization::withoutGlobalScopes()->find($connection->organization_id);
            }
        }

        if (TelegramApi::configured() && hash_equals((string) config('services.telegram.webhook_secret'), $secret)) {
            return Organization::withoutGlobalScopes()->find(config('services.telegram.org_id'));
        }

        return null;
    }
}
