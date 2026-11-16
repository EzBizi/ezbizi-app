<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One periodic screen capture: a JPEG on disk, indexed by this row.
 *
 * Mirrors MonitoringActivity's shape — an append-only log, not a
 * one-current-row device beat — except every row here is also the index to a
 * file, and that is why its retention and its erasure both had to be built
 * like Attachment's rather than like a plain append log.
 *
 * ── Why retention is `elsewhere`, not `window` ───────────────────
 *
 * `ezbizi:expire-data` deletes swept rows with a raw
 * `DB::table()->whereIn('id', ...)->delete()`, in chunks, deliberately outside
 * Eloquent — see its own docblock. That never fires a model event, so a
 * `window()` policy here would expire the ROW every night and never touch the
 * JPEG behind it: the file would orphan forever, silently, which is worse than
 * not sweeping at all because it looks like the table is being kept small.
 * `ezbizi:prune-screenshots` (App\Support\Monitoring\ScreenshotPruner) is the
 * named mechanism instead — it deletes the file THEN the row for each expired
 * screenshot, so a crash between the two leaves a JPEG-less row that the very
 * next run's `captured_at < cutoff` query still selects and can retry;
 * ExpireData's raw batch delete could never retry anything, because after it
 * runs the row is gone.
 *
 * ── Why the file no longer goes with the row automatically ───────
 *
 * This used to have a `deleting` hook that called `ScreenshotStore::delete()`
 * synchronously. That looked right and was wrong in the same way Attachment's
 * history already answered: `Anonymiser::dropRows()` deletes rows ONE AT A
 * TIME with `$row->delete()`, inside a transaction that spans every table the
 * purge touches — so a hook that deletes the file THERE deletes it before the
 * transaction is known to commit, and a later table's failure rolls the row
 * back while the bytes stay gone. It also lied to the report: `files_removed`
 * is counted from `personalDataFiles()`, which the hook bypassed, so a dry run
 * and a real run disagreed about whether the picture would go.
 *
 * So this has no `deleting` hook, same as Attachment: nothing here declares a
 * deletion refusal (nothing refuses to erase a screenshot the way a signed
 * contract refuses), so there is nothing for one to enforce. Callers are
 * responsible for the file the same way AttachmentsController::destroy is —
 * ScreenshotPruner deletes the file before the row it indexes, and the purge
 * reads `personalDataFiles()` and deletes the bytes after its transaction
 * commits. A caller that deletes a row through neither of those leaks the
 * file, which is the same risk Attachment carries and not a new one.
 */
class MonitoringScreenshot extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasFactory, HasUuids;
    use \App\Support\Live\RecordsParentChanges;

    /** A capture is its machine's change on the live feed (the filmstrip moves). */
    protected static array $changeParent = ['monitoring_devices', MonitoredDevice::class, 'device_id'];


    protected $table = 'monitoring_screenshots';

    public $timestamps = false;

    /** ezbizi:prune-screenshots' cutoff. See the class docblock for why this is not a swept `window()`. */
    public const RETENTION_DAYS = 30;

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
        return RetentionPolicy::elsewhere('the ezbizi:prune-screenshots command',
            'Screenshots older than 30 days are removed WITH their JPEGs by '
            .'ezbizi:prune-screenshots; the generic sweep\'s raw deletes cannot remove files.');
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
     * The bytes this row is the index to, for `Anonymiser` to delete after its
     * transaction commits — same shape as `Attachment::personalDataFiles()`.
     *
     * `disk` is the literal string `'local'`, not read off a column: unlike
     * Attachment, this table carries no `disk` column, because ScreenshotStore
     * always writes to Laravel's local disk regardless of `FILESYSTEM_DISK`
     * (see its own docblock). Leaving this null would fall back to
     * `FileStore::defaultDisk()` — the CONFIGURED default — which is only
     * 'local' until somebody changes that setting for customer uploads; this
     * class's file is on 'local' unconditionally, so the disk it is deleted
     * from has to be stated the same way rather than inferred from a setting
     * that governs a different store.
     *
     * @return array<array{disk: ?string, path: ?string}>
     */
    public function personalDataFiles(): array
    {
        return [['disk' => 'local', 'path' => $this->path]];
    }

    /**
     * The machine this capture came from — ScreenshotsController's read side
     * (Task 4) reaches THROUGH this to the device's `user` for §3, the same
     * shape DevicesController uses for `MonitoredDevice::user()`.
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(MonitoredDevice::class);
    }
}
