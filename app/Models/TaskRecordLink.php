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

class TaskRecordLink extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'record_type', 'record_id', 'display_snapshot', 'created_by', 'created_at'];

    protected function casts(): array
    {
        return ['display_snapshot' => 'array', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Governed record links and their allowlisted snapshots remain with current and archived tasks until deliberately unlinked or redacted.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Connects a task to another governed EzBizi record and caches only that registry declaration allowlisted display snapshot.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('record_type, record_id', PersonalDataEntry::CONTENT, 'The opaque type and identifier of the governed record connected to this task.', Erasure::keep('The identity is required to reauthorize the target and render an unavailable link without retaining its display data.')),
            new PersonalDataEntry('display_snapshot', PersonalDataEntry::CONTENT, 'The target declaration allowlisted display snapshot, which can name a customer, worker, or other person.', Erasure::erase()),
            new PersonalDataEntry('created_by', PersonalDataEntry::ACTIVITY, 'The person who created the governed link.', Erasure::keep('The referenced account is anonymised at its source while link history remains.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        $type = static::recordTypeFor($subject);

        if ($type === null) {
            return null;
        }

        return static::query()->where(function (Builder $query) use ($subject, $type): void {
            $query->where(fn (Builder $target) => $target
                ->where('record_type', $type)
                ->where('record_id', $subject->getKey()));

            if ($subject instanceof User) {
                $query->orWhere('created_by', $subject->getKey());
            }
        });
    }

    /** @return list<string> */
    public function personalDataToErase(Model $subject): array
    {
        return $this->record_type === static::recordTypeFor($subject)
            && $this->record_id === $subject->getKey()
                ? ['display_snapshot']
                : [];
    }

    private static function recordTypeFor(Model $subject): ?string
    {
        return match ($subject::class) {
            Customer::class => 'customer',
            Job::class => 'job',
            Vehicle::class => 'vehicle',
            InventoryItem::class => 'inventory_item',
            InspectionReport::class => 'inspection_report',
            InspectionRun::class => 'inspection_run',
            User::class => 'hr_person',
            Service::class => 'service',
            default => null,
        };
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
