<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Database\WrittenOnceRefusal;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A submitted inspection, frozen.
 *
 * Two write exceptions only, and both are named rather than implied:
 *
 *   1. `archived_at` — a filter, not a correction of what was written.
 *   2. §9 erasure — the columns HoldsPersonalData lists. Without this hole the
 *      first purge request fails because the guard refuses the row.
 *
 * The ordinary path (`$report->update()`, `$report->save()`) still refuses
 * answers: the updating event below allows only `archived_at`. Erasure uses
 * `saveQuietly()`, which skips that event and is allowed through by
 * WriteOnceBuilder because `answers` is a declared exception. That is the
 * same split AuditEvent relies on, with the builder hole made explicit so a
 * quiet save of `content_hash` on a signature still cannot slip through.
 */
class InspectionReport extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;
    use WrittenOnce;

    public static function personalDataPurpose(): string
    {
        return 'A finished inspection of a customer\'s site or equipment. The answers, '
            .'photographs, signatures and stamped customer facts are what the visit found, '
            .'and they are about that customer rather than about the person who filled them in.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the organization exists. A submitted inspection is evidence '
            .'of what was found on the day, and deleting it would leave the visit without a '
            .'record. Archive hides it from the default list; it does not remove it. Erasure '
            .'blanks the person and keeps the row.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('answers', PersonalDataEntry::CONTENT,
                'Whatever the named inspection asked for — a customer name copied from the '
                .'job, a signature, photographs of the work, free text. The list of fields '
                .'is in the version this report is pinned to, so it differs from one '
                .                'inspection to the next.',
                // `[]` rather than NULL: a forgotten write on submit would otherwise
                // look like a purged report. A real submit always has answers.
                Erasure::erase('[]'),
                tenantDefined: true),
            new PersonalDataEntry('submitted_by', PersonalDataEntry::ACTIVITY,
                'Who submitted the inspection. A foreign key, not a name.',
                Erasure::keep('Provenance of the visit. The key points at an account the '
                    .'purge anonymises at its one home, so no name survives here.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof Customer
            ? static::query()->where('customer_id', $subject->getKey())
            : null;
    }

    /**
     * Columns this row may still change after insert.
     *
     * Derived from `personalDataHeld()` where the disposition is ERASE, plus
     * `archived_at`. That is the point: the exception list cannot drift from the
     * erasure declaration.
     *
     * The weakness is that the exception is column-scoped, not caller-scoped.
     * Any `saveQuietly()` can write these columns, not only the Anonymiser. Today
     * that is just `answers`. The day someone marks `definition_name` or a
     * stamped customer fact as ERASE, that column silently becomes writable on a
     * frozen report forever — see that cost before adding an entry.
     *
     * @return list<string>
     */
    public static function writeOnceExceptions(): array
    {
        $columns = ['archived_at'];

        foreach (static::personalDataHeld() as $entry) {
            if ($entry->erasure->disposition === Erasure::ERASE) {
                array_push($columns, ...$entry->columns());
            }
        }

        return array_values(array_unique($columns));
    }

    protected $fillable = [
        'organization_id', 'customer_id', 'job_id',
        'inspection_definition_id', 'inspection_definition_version_id',
        'submitted_by', 'submitted_at', 'archived_at', 'answers',
        'layout_preset', 'definition_name',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Answers as an array, including the `[]` stand-in Anonymiser writes.
     *
     * The purge forceFills the Erasure stand-in as a string. An `array` cast
     * would json-encode that string and store `"[]"` — a JSON string, not an
     * empty object — so this accessor treats `[]` as empty answers instead.
     *
     * @return Attribute<array<string, mixed>, array<string, mixed>|string>
     */
    protected function answers(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                if ($value === null || $value === '') {
                    return [];
                }
                if (is_array($value)) {
                    return $value;
                }
                $decoded = json_decode((string) $value, true);

                return is_array($decoded) ? $decoded : [];
            },
            set: function (mixed $value): string {
                if ($value === '[]' || $value === '{}') {
                    return '[]';
                }
                if ($value === null) {
                    return '[]';
                }

                return is_string($value) ? $value : json_encode($value);
            },
        );
    }

    protected static function booted(): void
    {
        static::updating(function (self $report) {
            $ignore = array_filter([$report->getUpdatedAtColumn()]);
            $meaningful = array_values(array_diff(array_keys($report->getDirty()), $ignore));
            $allowed = ['archived_at'];

            if ($meaningful === [] || array_diff($meaningful, $allowed) !== []) {
                throw new WrittenOnceRefusal(
                    'Inspection reports are frozen after submit. Archive is a filter, not '
                    .'an edit, and erasure is a rights request — neither is a correction of '
                    .'what was written. See '.self::class.' for the two holes, and why they '
                    .'are holes rather than an open write.'
                );
            }
        });

        static::deleting(function () {
            throw new WrittenOnceRefusal(
                'A submitted inspection cannot be deleted. Archive hides it; erasure blanks '
                .'the person. Removing the row would leave the visit without a record.'
            );
        });
    }

    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinition::class, 'inspection_definition_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinitionVersion::class, 'inspection_definition_version_id');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }
}
