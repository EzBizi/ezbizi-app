<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Support\Telegram\TelegramApi;
use App\Support\Telegram\TelegramStaffBot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram delivering an update to the staff Tech Assistant bot.
 *
 * Authenticated by X-Telegram-Bot-Api-Secret-Token, which Telegram echoes
 * from setWebhook (telegram:set-webhook). The tenant is the configured
 * TELEGRAM_ORG_ID, never anything in the body. Anything else answers 200 so
 * Telegram does not retry.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant, TelegramStaffBot $bot): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        if (! TelegramApi::configured() || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            return response()->json(['ok' => false], 401);
        }

        $message = $request->input('message');
        $organization = Organization::withoutGlobalScopes()->find(config('services.telegram.org_id'));

        if (is_array($message) && $organization !== null) {
            try {
                $tenant->run($organization, fn () => $bot->handle($organization, $message));
            } catch (Throwable $e) {
                Log::warning('A Telegram update could not be handled.', ['reason' => $e->getMessage()]);
            }
        }

        return response()->json(['ok' => true]);
    }
}
