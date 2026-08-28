<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Settings\SettingsResolver;
use App\Support\Time\FranchiseWallTime;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job (plan §5.2).
 *
 * NEVER shared. A job happens at one place, on one date, with one crew — it
 * belongs to exactly one franchise and shows up in that franchise's roll-ups.
 * Doing a job for another franchise's customer is normal and adds you to their
 * "served by" list; it does not make the job co-owned, and it does not move the
 * customer.
 *
 * `performed_by_user_id` **is** personal data (spec §5, §9): the record of who
 * performed work for a customer, kept as long as the job is, and nulled on
 * erasure while the job survives.
 */
#[Fillable([
    'franchise_id', 'customer_id', 'number', 'title', 'description',
    'status', 'scheduled_for', 'duration_minutes', 'amount_minor', 'currency',
    'assigned_role_id', 'performed_by_user_id',
])]
class Job extends Model implements StatesRetention
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

    /**
     * The row is kept on the same terms as the customer it was done for — see
     * Customer::retention(), which this mirrors on purpose: there is no age at
     * which last year's job stops being what an invoice refers to, and tax law
     * wants the invoices this table is found through.
     *
     * `performed_by_user_id` is the one column on this row that is personal
     * data, and it is why `jobs` states a policy at all rather than leaning on
     * that shared reasoning the way `organizations`/`companies`/`franchises` do
     * (see RetentionTest::test_every_table_in_the_schema_has_been_decided_about).
     * It is not anonymised the way `customers` is — the job survives whatever
     * happens to the person who did the work, and the FK
     * (`performed_by_user_id` → `users`, `nullOnDelete`) is what makes that
     * literally true: if that account is ever removed the constraint nulls this
     * column and leaves the rest of the row exactly as it was, rather than
     * taking the job down with it.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The work itself (§5.2), kept on the same terms as the customer it was done for '
            .'— there is no age at which last year\'s job stops being what an invoice refers '
            .'to. `performed_by_user_id` is personal data: who performed the work, kept for '
            .'as long as the job is. It is nulled — not anonymised — if that person\'s '
            .'account is ever removed, and the job survives that untouched. The customer, in '
            .'`customers`, and any photographs, in `attachments`, each decide about '
            .'themselves the same way.'
        );
    }

    protected function casts(): array
    {
        return [
            'scheduled_for'    => FranchiseWallTime::class,
            'duration_minutes' => 'integer',
            // Integer minor units (§7). Casting it keeps a string '1999' from a
            // JSON body out of arithmetic that would silently concatenate.
            'amount_minor'     => 'integer',
            // When the money was earned (§8.1). Cast so present() can call
            // ?->toIso8601String() and so a bare assignment of now() in
            // JobsController::update() serialises correctly on save — see
            // AutomationEvent::casts() for the same reasoning on delivered_at.
            'earned_at'        => 'datetime',
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

    public function lines(): HasMany
    {
        return $this->hasMany(JobLine::class)->orderBy('position')->orderBy('created_at');
    }

    public function assignedRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'assigned_role_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    /**
     * The total is what the lines come to — never what somebody typed.
     *
     * Call inside the transaction that changed a line, so a rolled-back line
     * cannot leave a total that outlived it.
     */
    public function recomputeTotal(): void
    {
        $this->forceFill([
            'amount_minor' => (int) $this->lines()->sum('amount_minor'),
        ])->save();
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
