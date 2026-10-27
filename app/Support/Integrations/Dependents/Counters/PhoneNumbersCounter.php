<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\PhoneNumber;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

final class PhoneNumbersCounter extends RowCounter
{
    protected function key(): string
    {
        return 'phone_numbers';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'phone';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return PhoneNumber::query()->where('connection_id', $ref->id);
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'phone number stops ringing and texting', 'phone numbers stop ringing and texting');
    }

    public function features(IntegrationRef $ref): array
    {
        if ($ref->kind !== 'phone') {
            return [];
        }

        return [
            Feature::breaks('Phone: calls and texts on this account\'s numbers stop'),
            Feature::degrades('Reports › Calls: call reports stop updating'),
        ];
    }
}
