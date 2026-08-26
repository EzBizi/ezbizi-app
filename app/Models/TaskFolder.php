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
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskFolder extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['company_id', 'franchise_id', 'name', 'archived_at'];

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Kept as part of the organization task structure, including archived folders, until the organization deliberately removes that structure.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Names used to organize task boards; a person name can be entered in this free-text label.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('name', PersonalDataEntry::CONTENT, 'The tenant-defined folder label shown around task boards.', Erasure::keep('It remains part of the tenant task structure; free text cannot be matched reliably to an erasure subject.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function boards(): HasMany
    {
        return $this->hasMany(TaskBoard::class, 'folder_id');
    }
}
