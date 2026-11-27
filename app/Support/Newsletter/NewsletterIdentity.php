<?php

namespace App\Support\Newsletter;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Newsletter;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who a newsletter is from, and whose customers it may reach, read from its scope.
 *
 * A company- or franchise-filed mailing speaks as that company: its name in the
 * footer and as the From name, its postal address in the footer. A franchise
 * speaks as the company it belongs to (franchises hold no address). Anything
 * the company leaves blank falls back to the organization.
 */
final class NewsletterIdentity
{
    /** @return array{company: string, address: string} */
    public static function footer(Newsletter $newsletter): array
    {
        $organization = Organization::query()->withoutGlobalScopes()->find($newsletter->organization_id);
        $company = self::company($newsletter->scope_type, $newsletter->scope_id);

        $name = trim((string) ($company?->name ?? ''));
        $address = $company === null ? '' : self::companyAddress($company);

        return [
            'company' => $name !== '' ? $name : (string) ($organization?->name ?? 'EzBizi'),
            'address' => $address !== '' ? $address : (string) ($organization?->formattedAddress() ?? ''),
        ];
    }

    /**
     * Restrict a customer query to the customers of this scope.
     *
     * A company reaches the customers owned by its franchises and its
     * sub-companies' franchises; a franchise reaches its own. An organization
     * scope reaches everybody in the tenant.
     */
    public static function customersOf(Builder $customers, ?string $scopeType, ?string $scopeId): Builder
    {
        $franchiseIds = match ($scopeType) {
            Newsletter::SCOPE_FRANCHISE => [(string) $scopeId],
            Newsletter::SCOPE_COMPANY => Company::query()->find($scopeId)?->allFranchises()->pluck('id')->all() ?? [],
            default => null,
        };

        return $franchiseIds === null
            ? $customers
            : $customers->whereIn((new Customer)->qualifyColumn('owning_franchise_id'), $franchiseIds);
    }

    /**
     * Every scope an opt-out recorded at would cover this one: the scope itself,
     * its company and that company's parent, and the organization.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    public static function coveringScopes(Newsletter $newsletter): array
    {
        $scopes = [[(string) $newsletter->scope_type, (string) $newsletter->scope_id]];
        $scopes[] = [Newsletter::SCOPE_ORGANIZATION, (string) $newsletter->organization_id];

        $company = self::company($newsletter->scope_type, $newsletter->scope_id);
        while ($company !== null) {
            $scopes[] = [Newsletter::SCOPE_COMPANY, (string) $company->getKey()];
            $company = $company->parent_company_id === null ? null : Company::query()->find($company->parent_company_id);
        }

        return $scopes;
    }

    private static function company(?string $scopeType, ?string $scopeId): ?Company
    {
        return match ($scopeType) {
            Newsletter::SCOPE_COMPANY => Company::query()->find($scopeId),
            Newsletter::SCOPE_FRANCHISE => Franchise::query()->find($scopeId)?->company,
            default => null,
        };
    }

    private static function companyAddress(Company $company): string
    {
        $cityLine = collect([$company->city, $company->state, $company->postal_code])
            ->map(fn ($part) => is_string($part) ? trim($part) : null)
            ->filter()
            ->implode(' ');

        return collect([
            is_string($company->address_line1) ? trim($company->address_line1) : null,
            is_string($company->address_line2) ? trim($company->address_line2) : null,
            $cityLine !== '' ? $cityLine : null,
        ])->filter()->implode(', ');
    }
}
