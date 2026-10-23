<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\User;
use App\Support\Telegram\TelegramStaffBot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A staff question or rating-plate photo from Telegram, answered by the Tech Assistant off the webhook. */
class AnswerTelegramWithTech implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would answer twice

    // Same queues as AnswerWhatsAppWithTech: a photo is a tool turn.
    public int $timeout = 75;

    public function __construct(public string $userId, public int $chatId, public string $text, public ?string $fileId = null)
    {
        if ($fileId !== null) {
            $this->timeout = 150;
            $this->onConnection('database-long')->onQueue('long');
        }
    }

    public function handle(TelegramStaffBot $bot, TenantContext $tenant): void
    {
        $organization = Organization::withoutGlobalScopes()->findOrFail(config('services.telegram.org_id'));

        $tenant->run($organization, fn () => $bot->techReply(
            $organization, User::query()->withoutGlobalScopes()->findOrFail($this->userId), $this->chatId, $this->text, $this->fileId,
        ));
    }
}
