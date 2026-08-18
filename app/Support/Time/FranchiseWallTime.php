<?php

namespace App\Support\Time;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A job's scheduled time is wall clock in the franchise timezone (TODO #20).
 *
 * Stored naive (`Y-m-d H:i:s`). Read back in `franchises.timezone`. An instant
 * would move on DST; 9am Tuesday for a technician would not.
 */
final class FranchiseWallTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value, $this->timezone($model, $attributes));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance(\DateTimeImmutable::createFromInterface($value))
                ->format('Y-m-d H:i:s');
        }

        return Carbon::parse((string) $value)->format('Y-m-d H:i:s');
    }

    /** @param  array<string, mixed>  $attributes */
    private function timezone(\Illuminate\Database\Eloquent\Model $model, array $attributes): string
    {
        if ($model->relationLoaded('franchise') && is_string($model->franchise?->timezone) && $model->franchise->timezone !== '') {
            return $model->franchise->timezone;
        }

        $franchiseId = $model->getAttribute('franchise_id') ?? ($attributes['franchise_id'] ?? null);

        if ($franchiseId) {
            $tz = \App\Models\Franchise::query()->whereKey($franchiseId)->value('timezone');

            if (is_string($tz) && $tz !== '') {
                return $tz;
            }
        }

        return 'UTC';
    }
}
