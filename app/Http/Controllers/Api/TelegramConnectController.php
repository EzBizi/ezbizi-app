<?php

namespace App\Http\Controllers\Api;

use App\Models\SocialConnection;
use App\Models\TelegramLink;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use App\Support\Social\SocialProvider;
use App\Support\Telegram\TelegramApi;
use App\Support\Telegram\TelegramBotStatus;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * An organization's own Telegram bot for the staff Tech Assistant, on Settings
 * › Integrations › Messaging (Jose, 2026-10-26). Before this the bot was env
 * only (docs/TELEGRAM-BOT.md) and there was nowhere in the app to set it up.
 *
 * Connect: the BotFather token is checked with getMe before anything is
 * written; a random webhook secret is generated and setWebhook points the bot
 * at this server with it. That secret is how the webhook knows the tenant.
 *
 * One bot per organization, at the organization: the staff it serves are the
 * organization's, so a company view cannot set it (authority does not flow up).
 */
class TelegramConnectController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** POST /api/v1/social/telegram */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $provider = SocialProvider::Telegram;
        $this->mayManage($user);

        $data = $request->validate([
            'bot_token' => ['required', 'string', 'max:200'],
        ], [
            'bot_token.required' => 'Paste the token @BotFather gave you for the bot.',
        ]);
        $token = trim($data['bot_token']);

        abort_unless(
            ViewScope::forUser($user)->isOrganization(),
            Status::HTTP_UNPROCESSABLE_ENTITY,
            'A Telegram bot serves the whole organization. Switch to the organization view to connect it.',
        );

        if (! preg_match('/^\d{3,20}:[A-Za-z0-9_-]{20,100}$/', $token)) {
            throw ValidationException::withMessages(['bot_token' => 'That does not look like a bot token. Copy the whole line @BotFather sent, like 123456:ABC-DEF…']);
        }

        $api = TelegramApi::withToken($token);
        $bot = $this->verify($api);
        $remoteId = (string) $bot['id'];

        $existing = TelegramApi::connectionFor((string) $user->organization_id);
        if ($existing !== null && $existing->remote_id !== $remoteId) {
            throw ValidationException::withMessages(['bot_token' => 'This organization already has a Telegram bot ('.$existing->display_name.'). Disconnect it first.']);
        }

        $elsewhere = SocialConnection::query()->withoutGlobalScopes()
            ->where('provider', $provider->value)
            ->where('remote_id', $remoteId)
            ->where('organization_id', '!=', $user->organization_id)
            ->exists();
        if ($elsewhere) {
            throw ValidationException::withMessages(['bot_token' => 'That bot is connected to another organization. Create a new bot with @BotFather for this one.']);
        }

        $secret = Str::random(48);
        try {
            $api->call('setWebhook', [
                'url' => route('telegram.webhook'),
                'secret_token' => $secret,
                'allowed_updates' => ['message'],
                'drop_pending_updates' => true,
            ]);
        } catch (\RuntimeException|ConnectionException) {
            throw ValidationException::withMessages(['bot_token' => 'Telegram accepted the token but would not point the bot at EzBizi. Try again in a minute.']);
        }

        $username = (string) ($bot['username'] ?? '');
        $name = (string) ($bot['first_name'] ?? '');

        $connection = $existing ?? new SocialConnection;
        if (! $connection->exists) {
            $connection->forceFill([
                'organization_id' => $user->organization_id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => (string) $user->organization_id,
                'provider' => $provider->value,
                'remote_id' => $remoteId,
            ]);
        }
        $connection->forceFill([
            'display_name' => $name !== '' ? $name : '@'.$username,
            'handle' => $username !== '' ? '@'.$username : null,
        ]);
        // Encrypted and masked like every other connection's credentials.
        $connection->connect([
            'refresh_token' => $token,
            'bot_token' => $token,
            'webhook_secret' => $secret,
        ]);
        $connection->save();
        TelegramBotStatus::forget($connection);

        $this->audit->log('social.connected', $connection, [
            'provider' => $provider->value,
            'display_name' => $connection->display_name,
            'scope_type' => $connection->scope_type,
        ], actor: $user);

        return response()->json(['data' => [
            'id' => $connection->getKey(),
            'provider' => $provider->value,
            'provider_label' => $provider->label(),
            'display_name' => $connection->display_name,
            'handle' => $connection->handle,
            'scope_type' => $connection->scope_type,
            'scope_id' => $connection->scope_id,
            'message' => $provider->connectedSentence(1, (string) $connection->display_name),
        ]], Status::HTTP_CREATED);
    }

    /** GET /api/v1/social/telegram/links — the staff linked to this organization's bot. */
    public function links(Request $request): JsonResponse
    {
        $this->mayManage($request->user());

        $links = TelegramLink::query()->with('user:id,name,email')->orderByDesc('linked_at')->get();

        return response()->json(['data' => $links->map(fn (TelegramLink $l) => [
            'id' => $l->getKey(),
            'user_id' => $l->user_id,
            'name' => $l->user?->name ?? 'A removed staff member',
            'email' => $l->user?->email,
            'linked_at' => $l->linked_at?->toIso8601String(),
        ])->all()]);
    }

    /** DELETE /api/v1/social/telegram/links/{link} — the same as /unlink in Telegram. */
    public function unlink(Request $request, string $link): JsonResponse
    {
        $user = $request->user();
        $this->mayManage($user);

        // Tenant-scoped: another organization's link is a 404.
        $row = TelegramLink::query()->with('user:id,name')->find($link);
        abort_if($row === null, Status::HTTP_NOT_FOUND);

        $this->audit->log('telegram_link.unlinked', $row, [
            'staff' => $row->user?->name,
            'user_id' => $row->user_id,
        ], actor: $user);

        $row->delete();

        return response()->json(['data' => ['id' => $link]]);
    }

    private function mayManage(User $user): void
    {
        $provider = SocialProvider::Telegram;

        abort_unless(
            $user->hasPermission($provider->connectPermission()),
            Status::HTTP_FORBIDDEN,
            Refusal::permission($provider->connectAct())->message(),
        );
    }

    /**
     * getMe, refused with a sentence. Telegram's own description is not
     * echoed: it can repeat what was sent.
     *
     * @return array<string, mixed>
     */
    private function verify(TelegramApi $api): array
    {
        try {
            $bot = $api->call('getMe');
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['bot_token' => 'Telegram did not answer. Try again in a minute.']);
        } catch (\RuntimeException) {
            throw ValidationException::withMessages(['bot_token' => 'Telegram did not accept that token. Copy it again from @BotFather, or send /token there for a new one.']);
        }

        if (empty($bot['id']) || ($bot['is_bot'] ?? false) !== true) {
            throw ValidationException::withMessages(['bot_token' => 'That token is not a bot\'s. Create one with @BotFather.']);
        }

        return $bot;
    }
}
