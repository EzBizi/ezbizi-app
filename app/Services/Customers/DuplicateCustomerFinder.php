<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Possible duplicates, surfaced for a human (plan §5.2).
 *
 * NEVER auto-merges. Automatic merging of customer records is unrecoverable when
 * it is wrong — two people at the same address with the same surname are not one
 * person, and un-merging afterwards means reconstructing which job belonged to
 * whom from memory.
 *
 * Nor does it block. A second household on the same phone number is real, and a
 * create path that refuses it teaches people to enter bad data to get past the
 * validation.
 *
 * What matches is a SETTING, organization-wide, because sharing means duplicates
 * cross franchise boundaries and one franchise cannot sensibly decide the rule
 * for everyone else.
 */
class DuplicateCustomerFinder
{
    public function __construct(
        private readonly SettingsResolver $settings,
    ) {}

    /**
     * @param  Model  $scope  where the setting is read from
     * @return Collection<int, Customer>
     */
    public function candidates(array $attributes, Model $scope, ?string $ignoreId = null): Collection
    {
        $mode = (string) $this->settings->get('customers.duplicate_match_on', $scope);

        if ($mode === 'off') {
            return collect();
        }

        $query = Customer::query()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));

        $phone = $this->normalisePhone($attributes['phone'] ?? null);
        $email = $this->normaliseEmail($attributes['email'] ?? null);

        $matched = match ($mode) {
            'phone' => $phone === null ? null : $query->whereRaw(
                $this->phoneExpression(), [$phone]
            ),

            'email' => $email === null ? null : $query->where('email', $email),

            'phone_or_email' => ($phone === null && $email === null) ? null : $query->where(
                function ($q) use ($phone, $email) {
                    if ($phone !== null) {
                        $q->orWhereRaw($this->phoneExpression(), [$phone]);
                    }
                    if ($email !== null) {
                        $q->orWhere('email', $email);
                    }
                }
            ),

            'name_and_address' => blank($attributes['name'] ?? null) ? null : $query
                ->where('name', $attributes['name'])
                ->where('postal_code', $attributes['postal_code'] ?? null),

            default => null,
        };

        // Nothing to match ON is not the same as no duplicates — a customer with
        // neither phone nor email simply cannot be checked this way, and saying
        // "no duplicates found" would be a claim the data does not support.
        if ($matched === null) {
            return collect();
        }

        // Same load as the list (CustomersController::index) on purpose: this
        // payload goes through the same present(), where a franchise selected
        // without company_id makes `company` null — indistinguishable from a
        // franchise that genuinely has none.
        return $matched
            ->with('owningFranchise:id,name,company_id', 'owningFranchise.company:id,name')
            ->limit(10)
            ->get();
    }

    /**
     * Compare digits only.
     *
     * (555) 123-4567 and 555-123-4567 are the same number typed by two people,
     * and a literal comparison finds neither of them.
     */
    private function normalisePhone(?string $phone): ?string
    {
        if (blank($phone)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        // Fewer than seven digits is an extension or a typo, not something worth
        // matching half the organization against.
        return strlen((string) $digits) >= 7 ? $digits : null;
    }

    private function normaliseEmail(?string $email): ?string
    {
        return blank($email) ? null : strtolower(trim($email));
    }

    /**
     * Strips the stored value down to digits for comparison.
     *
     * A generated column with an index would be the fast version; at the scale
     * this runs — once per customer create, against one organization's rows —
     * the scan is cheaper than the migration. Revisit if it shows up in a slow
     * query log.
     */
    private function phoneExpression(): string
    {
        return "REGEXP_REPLACE(COALESCE(phone, ''), '[^0-9]', '') = ?";
    }
}
