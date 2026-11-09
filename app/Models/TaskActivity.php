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

/**
 * One change to a task, for its Activity log (Jose, 2026-11-09, monday's):
 * which field, from what to what, by whom. Written only by TaskWriter, as the
 * words the board shows, never ids. A null user is an automation or the system.
 */
class TaskActivity extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['organization_id', 'task_id', 'user_id', 'field', 'label', 'from', 'to'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'A task\'s activity log: who changed which of its fields, and the values before and after.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('An activity row hangs off its task and goes with it.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which person made each change recorded in the task activity log.',
                Erasure::erase()),
            new PersonalDataEntry('from, to', PersonalDataEntry::CONTENT,
                'The field\'s value before and after, as the board shows it.',
                Erasure::keep('It describes the business\'s work, like the task\'s own fields; '
                    .'the link to the person is the column erased above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }
}
