<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/** One year of a customer's membership. A renewal is the next row. */
#[Fillable([
    'franchise_id', 'customer_id', 'membership_plan_id', 'enrollment',
    'starts_on', 'ends_on', 'cancelled_at', 'reminded_at', 'visit_job_id', 'followed_up_at', 'paid_minor',
])]
class Membership extends Model implements StatesRetention
{
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): filed on its branch. */
    protected static string $changeType = 'memberships';

    use HasUuids;

    public const ENROLLMENTS = ['new', 'renewal'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A year of a customer\'s maintenance plan and what they paid for it. Kept with '
            .'the organization, as estimates are; erasing the customer anonymises the customer '
            .'in place. Removing the organization takes it.'
        );
    }

    protected function casts(): array
    {
        return [
            'starts_on'    => 'immutable_date',
            'ends_on'      => 'immutable_date',
            'cancelled_at' => 'datetime',
            'reminded_at'  => 'datetime',
            'followed_up_at' => 'datetime',
            'paid_minor'   => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function state(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $today->gt($this->ends_on) => 'expired',
            $today->lt($this->starts_on) => 'upcoming',
            default => 'active',
        };
    }

    /**
     * What is owed by today: the whole price for a yearly plan, or one twelfth
     * per month begun for a monthly one, less what was paid. Never negative.
     */
    public function dueMinor(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();
        $price = (int) $this->plan->price_minor;

        $owed = $this->plan->billing === 'monthly'
            ? intdiv($price * min(12, max(0, (int) $this->starts_on->diffInMonths($today) + 1)), 12)
            : $price;

        return max(0, $owed - $this->paid_minor);
    }

    public function visitJob(): BelongsTo
    {
        return $this->belongsTo(Job::class, 'visit_job_id');
    }

    /**
     * `{{ confirm_visit_url }}` in the renewal reminder: the signed page where
     * the customer picks the date of their next visit. Good until a month
     * after the year ends.
     */
    public function getConfirmVisitUrlAttribute(): string
    {
        return URL::temporarySignedRoute(
            'memberships.visit',
            $this->ends_on->addMonths(max(1, (int) app(\App\Support\Settings\SettingsResolver::class)->get('customers.membership_visit_months', Organization::query()->withoutGlobalScopes()->findOrFail($this->organization_id))))->endOfDay(),
            ['membership' => $this->getKey()],
        );
    }
}
