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

/**
 * The unit a tech is working on, remembered between their messages (model,
 * serial, fuel). One open per person; "new job" or 12 idle hours closes it.
 */
class AssistantJob extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const IDLE_HOURS = 12;

    protected $table = 'assistant_jobs';

    protected $fillable = ['user_id', 'channel', 'brand', 'model', 'serial', 'fuel', 'ignition', 'parts_discussed', 'closed_at'];

    protected function casts(): array
    {
        return ['parts_discussed' => 'array', 'closed_at' => 'datetime'];
    }

    public static function personalDataPurpose(): string
    {
        return 'Which unit a technician is working on, so the assistant does not ask for the model again.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'updated_at', 'A technician\'s working notes on one unit, kept three months.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY,
                'Which technician was working on this unit, for their own next message.', Erasure::drop('Working notes; dropping them loses only the assistant\'s memory.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }
}
