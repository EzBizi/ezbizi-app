<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\WhatsAppNumber;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

final class WhatsAppNumbersCounter extends RowCounter
{
    protected function key(): string
    {
        return 'whatsapp_numbers';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'social';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return WhatsAppNumber::query()->where('social_connection_id', $ref->id);
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'WhatsApp number stops sending and receiving', 'WhatsApp numbers stop sending and receiving');
    }
}
