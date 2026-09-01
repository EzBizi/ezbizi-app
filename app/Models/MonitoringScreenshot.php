<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Monitoring\ScreenshotStore;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One periodic screen capture: a JPEG on disk, indexed by this row.
 *
 * Mirrors MonitoringActivity's shape — an append-only log, not a
 * one-current-row device beat — except every row here is also the index to a
 * file, so deleting the row without deleting the file would leave a picture
 * of somebody's screen on disk with nothing left pointing at it. `booted()`
 * closes that: the file goes with the row, whichever caller deletes it.
 */
class MonitoringScreenshot extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'monitoring_screenshots';

    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'device_id', 'user_id', 'captured_at',
        'path', 'bytes', 'width', 'height',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'bytes'       => 'integer',
        'width'       => 'integer',
        'height'      => 'integer',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(1, 'captured_at',
            'A month of screen captures, then gone.');
    }

    public static function personalDataPurpose(): string
    {
        return "Periodic pictures of a person's screen, shown to their organization.";
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('path', PersonalDataEntry::CONTENT,
                'The image itself — a picture of whatever was on screen at the moment.',
                Erasure::drop('The row is nothing but the picture and who it is of; the row and '
                    .'the JPEG behind it are removed together.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }

    /**
     * The JPEG goes with the row wherever a row is deleted through Eloquent —
     * directly, as here, or by the purge's `Erasure::drop` (Anonymiser calls
     * `$row->delete()` per row on that path). `ezbizi:expire-data`'s nightly
     * sweep does NOT go through this: it deletes expired rows with a raw
     * `DB::table()->delete()` for every swept table, which never fires model
     * events. See task-1-report.md — closing that gap is out of this task's
     * scope, and is flagged there rather than silently left.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $screenshot) {
            app(ScreenshotStore::class)->delete($screenshot->path);
        });
    }
}
