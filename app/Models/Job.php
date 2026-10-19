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
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * performed work for a customer. See Job::retention() for what erasure does
 * to it — the key is KEPT; the person's own row is anonymised instead.
 */
#[Fillable([
    'franchise_id', 'customer_id', 'number', 'title', 'description',
    'status', 'scheduled_for', 'duration_minutes', 'amount_minor', 'currency',
    'assigned_role_id', 'performed_by_user_id', 'service_area_id',
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
     *
     * It is KEPT, not nulled, when that person is erased. §9 erasure of a user
     * anonymises their own row in place — see User::retention(): "erasing one
     * means anonymising it rather than removing the row" — it is never a
     * delete, so the migration's `nullOnDelete()` FK is not what enforces
     * this; it is a database backstop for a row deletion §9's normal path
     * never takes. The real mechanism is the one
     * Attachment::personalDataHeld() already states for `uploaded_by_user_id`,
     * the closest existing analogue: the person's own row is anonymised at
     * its one home, and this key goes on pointing at that now-nameless row —
     * so the provenance of who did the work survives without identifying
     * anybody.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The work itself (§5.2), kept on the same terms as the customer it was done for '
            .'— there is no age at which last year\'s job stops being what an invoice refers '
            .'to. `performed_by_user_id` is personal data: who performed the work. It is kept, '
            .'not nulled, when that person is erased — their own row is anonymised at its one '
            .'home (`users`) rather than deleted, so this key goes on pointing at a real row '
            .'with no name left in it. The customer, in `customers`, and any photographs, in '
            .'`attachments`, each decide about themselves the same way.'
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
            // Set only by a person's change; the Workiz projection leaves the
            // area alone once it is (see its migration).
            'service_area_chosen_at' => 'datetime',
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

    /**
     * The sub-division of this job's franchise the work is in -- Scheduling's
     * service area. Null when the job could not be placed without guessing;
     * see JobServiceArea for the rule.
     */
    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /** The Workiz mirror row this job was projected from, if any -- where its money lives. */
    /** The company the job's franchise belongs to, by name: the export's `company`. */
    public function getCompanyAttribute(): ?string
    {
        return $this->franchise?->company?->name;
    }

    public function workizJob(): HasOne
    {
        return $this->hasOne(WorkizJob::class);
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

    /**
     * Keep `earned_at` in step with whether this job is earned: stamp it on the
     * way IN to an earned status, clear it on the way OUT of every earned one.
     *
     * The invariant, from 2026-09-24: `earned_at` is NOT NULL exactly when the
     * job's status is earned. Status decides WHETHER a job counts; `earned_at`
     * decides WHEN. §8.1's "a closed month stays closed" still holds for
     * everything that stays earned -- the first moment is kept while the job is
     * earned, including across a move between two earned statuses. What clears
     * is a job that stops being earned, which is a real change, not a re-read.
     *
     * Why clearing, and why now: Workiz re-reads reaching old closed jobs move
     * some that a stale A1 Tools copy had as Done back to "done pending
     * approval", which Jose ruled is not earned ("Only Done"). Kept, their stamp
     * would count revenue that was never earned. Re-entering Done later stamps
     * the moment Workiz actually reports, not the stale one.
     *
     * The name predates the clearing branch and is kept so the two callers --
     * JobsController and the Workiz follow pass, both of which call this on ANY
     * status change -- gain the clear with no change of their own.
     *
     * The ONE place that decides when money was earned (spec §8.1, §8.2). It
     * used to live inline in JobsController::update, which made the API its
     * only writer: a job whose status changed any other way — the Workiz
     * projection following a job to Done — could never reach revenue, because
     * Revenue reads `whereNotNull('earned_at')` with no status clause. A second,
     * inlined copy would let the API and the projection disagree about the
     * month, so both call this.
     *
     * It decides three things and deliberately NOT a fourth:
     *   - at most once: an already-earned job keeps its first moment, so a
     *     closed month stays closed;
     *   - only for a status the organization counts as earned;
     *   - at the moment given, or now.
     * WHETHER the status just changed is the CALLER's to decide, because only
     * the caller knows. JobsController calls this only when `status` is dirty —
     * otherwise correcting the title of an old completed job would stamp it
     * into the current month. A backfill, by contrast, wants exactly the
     * already-earned jobs, and calls it on them directly.
     *
     * `$at` is any instant, in ANY zone, and it is stored as that instant.
     * Normalised to UTC here, by construction, because Laravel formats a
     * Carbon in the zone it is HOLDING and this column is UTC: a caller passing
     * 12:30 in New York used to get 12:30 UTC stored -- four hours early, and at
     * the end of a month, in the wrong month. Workiz hit it on 2026-09-24 and
     * their test caught it. Worse, this docblock recommended that exact call.
     * It is safe now whatever zone the caller holds.
     *
     * What the caller still has to get right is which zone a NAKED wall-clock
     * time belongs to, because only the caller knows. Workiz's
     * `last_status_update` has no zone and is the franchise's local time, so
     * parse it there -- `Carbon::parse($wall, $this->franchise->timezone)` --
     * and pass the result; this method takes it from there.
     *
     * Sets the attribute and does not save; the caller saves, inside whatever
     * transaction already holds the record and its audit row.
     */
    public function markEarnedIfDue(?\DateTimeInterface $at = null): void
    {
        // Left every earned status: no longer earned, so no earned moment.
        // Cleared rather than kept, because revenue reads whereNotNull with no
        // status clause -- a stamp left on a job that is not earned is revenue
        // that was never earned. Checked FIRST, before the once-guard below,
        // or an already-stamped job could never be cleared.
        if (! \App\Support\Jobs\EarnedStatuses::isEarned((string) $this->status, $this->franchise)) {
            $this->earned_at = null;

            return;
        }

        // Still earned: keep the first moment. Moving between two earned
        // statuses does not move the month (§8.1).
        if ($this->earned_at !== null) {
            return;
        }

        $this->earned_at = $at !== null
            ? \Illuminate\Support\Carbon::instance($at)->utc()
            : now()->utc();
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

    /**
     * Words a message on a job can use that no job payload carries (see
     * Lead::automationMessageFields). `{{company_name}}` is the company of the
     * franchise that did the job, e.g. "A-1 Chimney" for "Nationwide".
     *
     * @return list<string>
     */
    public static function automationMessageFields(): array
    {
        return ['company_name', 'review_link'];
    }

    public function getCompanyNameAttribute(): string
    {
        return (string) ($this->franchise?->company?->name ?? '');
    }

    /**
     * `{{ review_link }}` on a job's automation: the customer's own reviews
     * link (Memberships, 2026-10-05), so the thank-you after a job lets the
     * review faces know who smiled. The site is the one for the franchise that
     * did the job, and the token names the job too, expiring (Jose, 2026-10-08).
     */
    public function getReviewLinkAttribute(): ?string
    {
        $customer = Customer::query()->find($this->customer_id);

        return $customer === null ? null : \App\Support\Memberships\CustomerLinks::jobReviewLink($customer, $this);
    }
}
