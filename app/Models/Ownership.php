<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who owns an area of a company or branch, or a step of the work (Jose, 2026-11-03). */
class Ownership extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['area', 'process'];

    public const STEPS = ['lead', 'booking', 'job', 'payment', 'review'];

    protected $fillable = ['organization_id', 'kind', 'key', 'scope_type', 'scope_id', 'user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Who owns an area of the business or a step of the work, shown on the ownership chart.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Kept while the person holds the responsibility; replaced when it moves.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'The person who owns this area or step, shown on the ownership chart.',
                Erasure::drop('Removed with the person: the area is left without an owner to fill again.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }
}
