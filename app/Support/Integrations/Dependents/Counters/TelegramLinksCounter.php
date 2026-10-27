<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\TelegramLink;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

/** Staff linked to the organization's Telegram bot, who stop getting answers there. */
final class TelegramLinksCounter extends RowCounter
{
    protected function key(): string
    {
        return 'telegram_links';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'social' && $ref->provider === 'telegram';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return TelegramLink::query()->withoutGlobalScopes();
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'staff member stops getting Tech Assistant answers on Telegram', 'staff stop getting Tech Assistant answers on Telegram');
    }

    public function features(IntegrationRef $ref): array
    {
        return $this->applies($ref)
            ? [Feature::breaks('Tech Assistant › Telegram: staff stop getting answers on Telegram')]
            : [];
    }
}
