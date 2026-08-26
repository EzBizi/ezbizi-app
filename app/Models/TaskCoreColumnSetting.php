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

class TaskCoreColumnSetting extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'core_key', 'display_label'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Shared core-column labels remain board configuration until the organization changes or removes the board.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Tenant-written display labels for structural columns on a task board.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('display_label', PersonalDataEntry::CONTENT, 'The shared free-text display label selected for a structural task column.', Erasure::keep('It is shared board configuration and cannot be attributed reliably to one erasure subject.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }
}
