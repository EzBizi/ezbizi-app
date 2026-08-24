<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Settings\SettingsResolver;
use App\Support\Time\FranchiseWallTime;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job (plan §5.2).
 *
 * NEVER shared. A job happens at one place, on one date, with one crew — it
 * belongs to exactly one franchise and shows up in that franchise's roll-ups.
 * Doing a job for another franchise's customer is normal and adds you to their
 * "served by" list; it does not make the job co-owned, and it does not move the
 * customer.
 */
#[Fillable([
    'franchise_id', 'customer_id', 'number', 'title', 'description',
    'status', 'scheduled_for', 'duration_minutes', 'amount_minor', 'currency',
])]
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;
    /*
     * Photographs, invoices from a supplier, a signed worksheet.
     *
     * The first attachable record in the app, and the one a review named: work
     * gets disputed over the photograph of what was found on arrival, and until
     * now that photograph lived in somebody's phone. Soft deletes and files
     * interact — see HasAttachments, which keeps them through a soft delete and
     * takes them on a force delete.
     */
    use HasAttachments;

    protected function casts(): array
    {
        return [
            'scheduled_for'    => FranchiseWallTime::class,
            'duration_minutes' => 'integer',
            // Integer minor units (§7). Casting it keeps a string '1999' from a
            // JSON body out of arithmetic that would silently concatenate.
            'amount_minor'     => 'integer',
        ];
    }

    // ── relations ────────────────────────────────────────────────

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ── status (§2.1 category 5) ─────────────────────────────────

    /**
     * The statuses this job's organization allows, as the values jobs store.
     *
     * Tenant configuration, not an enum in code: an organization wanting its own
     * word for "on hold" should not need a migration. The SET is data; the fact
     * that a job HAS a status is a platform invariant and stays here.
     *
     * @return list<string>
     */
    public static function allowedStatuses(Model $scope): array
    {
        return array_column(self::statusEntries($scope), 'value');
    }

    /**
     * Value plus the words people see.
     *
     * Older rows stored a bare string. The settings editor now sends
     * `{value, label}` so renaming the label does not orphan jobs, and so a
     * pipeline of `in_progress` can read as "In progress".
     *
     * @return list<array{value: string, label: string}>
     */
    public static function statusEntries(Model $scope): array
    {
        return self::normalizeStatuses(
            app(SettingsResolver::class)->get('jobs.statuses', $scope),
        );
    }

    public static function labelFor(string $status, Model $scope): string
    {
        foreach (self::statusEntries($scope) as $entry) {
            if ($entry['value'] === $status) {
                return $entry['label'];
            }
        }

        return self::humanizeStatus($status);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function normalizeStatuses(mixed $statuses): array
    {
        $out = [];

        foreach (is_array($statuses) ? $statuses : [] as $item) {
            if (is_array($item)) {
                $value = trim((string) ($item['value'] ?? ''));
                $label = trim((string) ($item['label'] ?? $value));
            } else {
                $value = trim((string) $item);
                $label = self::humanizeStatus($value);
            }

            if ($value === '') {
                continue;
            }

            $out[] = [
                'value' => $value,
                'label' => $label === '' ? $value : $label,
            ];
        }

        return $out;
    }

    public static function humanizeStatus(string $status): string
    {
        $words = trim(str_replace('_', ' ', $status));

        return $words === '' ? $status : ucwords($words);
    }

    // ── money (§7) ───────────────────────────────────────────────

    /**
     * For display only. Never feed this back into a calculation — the integer is
     * the value, and a float that has been through division is not it.
     */
    public function amountForDisplay(): ?string
    {
        if ($this->amount_minor === null) {
            return null;
        }

        return number_format($this->amount_minor / 100, 2);
    }
}
