<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\NewsletterSend;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

final class QueuedNewslettersCounter extends RowCounter
{
    protected function key(): string
    {
        return 'newsletters';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'social';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return NewsletterSend::query()->where('connection_id', $ref->id)->whereIn('status', [NewsletterSend::STATUS_QUEUED, NewsletterSend::STATUS_SENDING]);
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'newsletter waiting to send will fail', 'newsletters waiting to send will fail');
    }
}
