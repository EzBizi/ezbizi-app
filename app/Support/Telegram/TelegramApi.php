<?php

namespace App\Support\Telegram;

use Illuminate\Support\Facades\Http;

/** The Telegram Bot API, over Laravel's HTTP client. The token never leaves config. */
final class TelegramApi
{
    public const MAX_TEXT = 4096;

    public const MAX_PHOTO_BYTES = 20 * 1024 * 1024;

    public static function configured(): bool
    {
        return filled(config('services.telegram.bot_token'))
            && filled(config('services.telegram.webhook_secret'))
            && filled(config('services.telegram.org_id'));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed> the `result`
     *
     * @throws \RuntimeException when Telegram says no
     */
    public function call(string $method, array $params = []): array
    {
        $response = Http::timeout(20)->asJson()->post($this->base().'/'.$method, $params);

        if (! $response->successful() || $response->json('ok') !== true) {
            throw new \RuntimeException('Telegram '.$method.' failed: '.($response->json('description') ?? $response->status()));
        }

        return (array) $response->json('result');
    }

    /** Plain text, split at 4096 characters (on a line break when one is near). */
    public function sendMessage(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        foreach (self::chunks($text) as $i => $chunk) {
            $this->call('sendMessage', array_filter([
                'chat_id' => $chatId,
                'text' => $chunk,
                'reply_markup' => $i === 0 ? $replyMarkup : null,
            ], fn ($v) => $v !== null));
        }
    }

    /** @return list<string> */
    public static function chunks(string $text, int $max = self::MAX_TEXT): array
    {
        $chunks = [];
        while (mb_strlen($text) > $max) {
            $head = mb_substr($text, 0, $max);
            $cut = mb_strrpos($head, "\n");
            if ($cut === false || $cut < $max / 2) {
                $cut = $max;
            }
            $chunks[] = mb_substr($text, 0, $cut);
            $text = ltrim(mb_substr($text, $cut), "\n");
        }
        $chunks[] = $text;

        return $chunks;
    }

    /**
     * A file the bot was sent, by file_id: getFile, then the bytes.
     *
     * @return array{bytes: string, path: string}
     */
    public function download(string $fileId): array
    {
        $file = $this->call('getFile', ['file_id' => $fileId]);
        $path = (string) ($file['file_path'] ?? '');
        if ($path === '') {
            throw new \RuntimeException('Telegram did not give a path for that file.');
        }

        $response = Http::timeout(60)->get('https://api.telegram.org/file/bot'.config('services.telegram.bot_token').'/'.$path);
        if (! $response->successful() || strlen($response->body()) > self::MAX_PHOTO_BYTES) {
            throw new \RuntimeException('Could not download that photo from Telegram.');
        }

        return ['bytes' => $response->body(), 'path' => $path];
    }

    private function base(): string
    {
        return 'https://api.telegram.org/bot'.config('services.telegram.bot_token');
    }
}
