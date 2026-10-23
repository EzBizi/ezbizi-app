<?php

namespace App\Support\Telegram;

use App\Jobs\AnswerTelegramWithTech;
use App\Models\AssistantAnswer;
use App\Models\Organization;
use App\Models\TelegramLink;
use App\Models\User;
use App\Support\Ai\GrokCli;
use App\Support\Assistant\TechAssistant;
use App\Support\Social\WhatsAppStaffBot;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The staff Tech Assistant on Telegram (second channel after WhatsApp).
 *
 * Telegram does not reveal a phone number, so an unlinked person is asked to
 * share theirs with a request_contact button. Only their OWN contact links
 * (contact.user_id === from.id): a forwarded card is somebody else's number.
 * Runs inside the bot organization's tenant context.
 */
final class TelegramStaffBot
{
    public const PER_MINUTE = 30;

    public const SHARE = 'Share my phone number';

    public function __construct(private readonly TelegramApi $api, private readonly TechAssistant $tech) {}

    /** @param  array<string, mixed>  $message  a Telegram Message */
    public function handle(Organization $organization, array $message): void
    {
        $fromId = (int) ($message['from']['id'] ?? 0);
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($fromId === 0 || $chatId === 0 || ($message['chat']['type'] ?? 'private') !== 'private' || ($message['from']['is_bot'] ?? false)) {
            return;
        }

        $key = 'telegram:'.$fromId;
        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return;
        }
        RateLimiter::hit($key, 60);

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));

        if (isset($message['contact'])) {
            $this->link($organization, $message, $fromId, $chatId);

            return;
        }

        $link = TelegramLink::query()->where('telegram_user_id', $fromId)->first();
        $user = $link === null ? null : User::query()->whereKey($link->user_id)->where('is_active', true)->first();

        if ($user === null) {
            $this->askForPhone($chatId, str_starts_with($text, '/start'));

            return;
        }

        if ($link->chat_id !== $chatId) {
            $link->forceFill(['chat_id' => $chatId])->save();
        }

        $command = mb_strtolower((string) preg_replace('/@\S+$/', '', explode(' ', $text)[0]));
        if ($command === '/start') {
            $this->api->sendMessage($chatId, "\u{1F44B} Hi {$user->name}. Ask me about a unit or a part, or send a rating plate photo. Type \"new job\" to start a new unit, /unlink to disconnect.");

            return;
        }
        if ($command === '/unlink') {
            $link->delete();
            $this->api->sendMessage($chatId, 'Unlinked. Share your phone number again any time to reconnect.', ['remove_keyboard' => true]);

            return;
        }

        if (! GrokCli::configured() || ! $this->tech->enabled($organization)) {
            $this->api->sendMessage($chatId, 'The tech assistant is turned off right now.');

            return;
        }

        if (! empty($message['photo'])) {
            $largest = collect($message['photo'])->sortByDesc(fn ($p) => (int) ($p['file_size'] ?? 0) ?: ((int) ($p['width'] ?? 0)) * ((int) ($p['height'] ?? 0)))->first();
            AnswerTelegramWithTech::dispatch($user->getKey(), $chatId, $text, (string) $largest['file_id']);
            $this->api->sendMessage($chatId, "\u{1F440} Reading that photo\u{2026}");

            return;
        }

        if (isset($message['voice']) || isset($message['audio']) || isset($message['video_note'])) {
            $this->api->sendMessage($chatId, WhatsAppStaffBot::typeIt((string) AssistantAnswer::query()
                ->where('user_id', $user->getKey())->latest('created_at')->value('question')));

            return;
        }

        if (isset($message['document'])) {
            $this->api->sendMessage($chatId, "\u{1F4C4} Got the file. Manuals are added in the app: AI Assistance \u{2192} Manuals, so every tech's answers can use them.");

            return;
        }

        if ($text === '') {
            return;
        }

        AnswerTelegramWithTech::dispatch($user->getKey(), $chatId, $text, null);
    }

    /** AnswerTelegramWithTech's half: the assistant's answer, a photo read when one came. */
    public function techReply(Organization $organization, User $user, int $chatId, string $text, ?string $fileId): void
    {
        $files = [];
        if ($fileId !== null) {
            try {
                $file = $this->api->download($fileId);
            } catch (\RuntimeException) {
                $this->api->sendMessage($chatId, "\u{274C} Could not download that photo. Try sending it again.");

                return;
            }
            $ext = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION));
            $files['photo.'.(in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic'], true) ? $ext : 'jpg')] = $file['bytes'];
        }

        try {
            $answer = $this->tech->answer($user, $organization, $text, 'telegram', $files, $files === [] ? 60 : 120);
        } catch (\RuntimeException) {
            $this->api->sendMessage($chatId, "\u{26A0}\u{FE0F} The tech assistant could not answer just now. Try again in a minute.");

            return;
        }

        $this->api->sendMessage($chatId, $answer['reply'] ?? "\u{1F937} I have nothing on that. Send the brand, model and fuel (NG/LP), or a rating plate photo.");
    }

    /** @param  array<string, mixed>  $message */
    private function link(Organization $organization, array $message, int $fromId, int $chatId): void
    {
        if ((int) ($message['contact']['user_id'] ?? 0) !== $fromId) {
            $this->api->sendMessage($chatId, 'Please share your own number with the button below.', $this->keyboard());

            return;
        }

        $user = WhatsAppStaffBot::staffByPhone((string) $organization->getKey(), (string) ($message['contact']['phone_number'] ?? ''));
        if ($user === null) {
            $this->api->sendMessage($chatId, "Sorry, this bot is for A1 staff. If you work with us, ask the office to add this phone number to your profile.", ['remove_keyboard' => true]);

            return;
        }

        TelegramLink::query()->where('telegram_user_id', $fromId)->delete();
        TelegramLink::create(['user_id' => $user->getKey(), 'telegram_user_id' => $fromId, 'chat_id' => $chatId, 'linked_at' => now()]);

        $this->api->sendMessage($chatId, "\u{2705} Linked to {$user->name}. Ask me about a unit or a part, or send a rating plate photo.", ['remove_keyboard' => true]);
    }

    private function askForPhone(int $chatId, bool $greet): void
    {
        $this->api->sendMessage($chatId,
            ($greet ? "\u{1F44B} Hi! This is the A1 staff Tech Assistant.\n" : '').'To use it, share your phone number so I can find your staff account.',
            $this->keyboard());
    }

    /** @return array<string, mixed> */
    private function keyboard(): array
    {
        return [
            'keyboard' => [[['text' => self::SHARE, 'request_contact' => true]]],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }
}
