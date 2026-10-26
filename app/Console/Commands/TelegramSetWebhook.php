<?php

namespace App\Console\Commands;

use App\Support\Telegram\TelegramApi;
use Illuminate\Console\Command;

/** Points the Telegram bot at this server (docs/TELEGRAM-BOT.md). */
class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook {url : https://<host>/api/v1/telegram/webhook}';

    protected $description = 'Register the Telegram bot webhook with the secret token, messages only';

    public function handle(TelegramApi $api): int
    {
        if (! TelegramApi::configured()) {
            $this->error('Set TELEGRAM_BOT_TOKEN, TELEGRAM_WEBHOOK_SECRET and TELEGRAM_ORG_ID first.');

            return self::FAILURE;
        }

        $url = (string) $this->argument('url');
        if (! str_starts_with($url, 'https://')) {
            $this->error('Telegram needs an https:// URL.');

            return self::FAILURE;
        }

        try {
            $api->call('setWebhook', [
                'url' => $url,
                'secret_token' => (string) config('services.telegram.webhook_secret'),
                'allowed_updates' => ['message'],
                'drop_pending_updates' => true,
            ]);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Telegram webhook set to '.$url);

        return self::SUCCESS;
    }
}
