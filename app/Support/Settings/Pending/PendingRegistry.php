<?php

namespace App\Support\Settings\Pending;

use App\Models\BusinessLocation;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\ServiceArea;
use App\Support\Scheduling\Openings;
use App\Models\Organization;
use App\Support\Memberships\CustomerLinks;
use App\Models\Mailbox;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use Illuminate\Database\Eloquent\Model;

/**
 * Everything Settings › Pending asks of each place.
 *
 * Two sources, so nothing is listed per tenant:
 *   - every SettingDefinition declared `expected: true` that resolves to
 *     nothing at a place (no value there and none inherited);
 *   - the record checks in checks(). A module adds one by appending to that
 *     list, the way SettingRegistry's groups are one list in one file.
 */
final class PendingRegistry
{
    public function __construct(
        private readonly SettingRegistry $settings,
        private readonly SettingsResolver $resolver,
    ) {}

    /** @return list<PendingCheck> */
    public function all(): array
    {
        return [...$this->fromSettings(), ...$this->checks()];
    }

    /** @return list<PendingCheck> */
    private function fromSettings(): array
    {
        $out = [];

        foreach ($this->settings->all() as $key => $definition) {
            if (! $definition->expected) {
                continue;
            }

            $out[] = new PendingCheck(
                key: "setting:{$key}",
                label: $definition->label,
                module: $this->settings->groupOf($key)['key'],
                // Asked only where it can be set: a company cannot fill an
                // organization-only setting, so it is not that company's gap.
                scopes: $definition->allowedScopes,
                permissions: array_values(array_filter([$definition->permissionRequired(), $definition->readPermission])),
                missing: fn (Model $place) => $this->isEmpty($this->resolver->get($key, $place)),
                fill: fn () => ['kind' => 'setting', 'key' => $key, 'type' => $definition->type],
            );
        }

        return $out;
    }

    /** @return list<PendingCheck> */
    private function checks(): array
    {
        $openCompany = fn () => [
            'kind'        => 'open',
            'destination' => 'settings',
            'hint'        => 'Open Settings, then Organization, then the company.',
        ];

        $openScheduling = fn () => [
            'kind'        => 'open',
            'destination' => 'scheduling',
            'tab'         => 'settings',
            'hint'        => 'Open Scheduling, then Settings, and add a service area with its time slots.',
        ];

        return [
            new PendingCheck(
                key: 'company.address',
                label: 'Address',
                module: 'organizations',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['organizations.edit'],
                missing: fn (Company $c) => blank($c->address_line1) || blank($c->city),
                fill: $openCompany,
            ),
            new PendingCheck(
                key: 'company.phone',
                label: 'Phone number',
                module: 'organizations',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['organizations.edit'],
                missing: fn (Company $c) => blank($c->phone),
                fill: fn (Company $c) => ['kind' => 'field', 'path' => "/companies/{$c->id}", 'field' => 'phone'],
            ),
            new PendingCheck(
                key: 'company.logo',
                label: 'Logo',
                module: 'organizations',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['organizations.edit'],
                missing: fn (Company $c) => blank($c->logo_path),
                fill: $openCompany,
            ),
            // Leads: hours nobody chose run on the 8-5 default, and customers
            // heard "we're closed" at 6 PM (Bar, 2026-10-21). A default is not
            // a gap to `expected`, so this asks whether anyone set them.
            new PendingCheck(
                key: 'leads.office_hours',
                label: 'Office hours',
                module: 'leads',
                scopes: [SettingDefinition::SCOPE_ORGANIZATION, SettingDefinition::SCOPE_COMPANY, SettingDefinition::SCOPE_FRANCHISE],
                permissions: ['leads.manage'],
                missing: fn (Model $place) => $this->resolver->sourceOf('leads.office_hours', $place) === null,
                fill: fn () => [
                    'kind'        => 'open',
                    'destination' => 'leads',
                    'hint'        => 'Open Leads, then Manage, then Office hours.',
                ],
            ),
            // Scheduling: a branch takes no bookings without an area that has a
            // week. A sub-company's branch books on its parent's areas.
            new PendingCheck(
                key: 'scheduling.service_area',
                label: 'Service area',
                module: 'scheduling',
                scopes: [SettingDefinition::SCOPE_FRANCHISE],
                permissions: ['scheduling.settings.view'],
                missing: fn (Franchise $f) => Company::find($f->company_id)?->parent_company_id === null
                    && ! ServiceArea::query()->where('franchise_id', $f->id)->exists(),
                fill: $openScheduling,
            ),
            new PendingCheck(
                key: 'scheduling.area_slots',
                label: 'Time slots for every service area',
                module: 'scheduling',
                scopes: [SettingDefinition::SCOPE_FRANCHISE],
                permissions: ['scheduling.settings.view'],
                missing: fn (Franchise $f) => ServiceArea::query()->where('franchise_id', $f->id)->whereNull('shares_with_id')->get()
                    ->contains(fn (ServiceArea $a) => ! Openings::hasCapacity($a)),
                fill: $openScheduling,
            ),
            // Customer Experience (2026-10-26). A branch whose review texts would
            // carry no link: nothing to tap, so no review and no Review links count.
            new PendingCheck(
                key: 'branch.review_link',
                label: 'Review link for the review texts',
                module: 'reviews',
                scopes: [SettingDefinition::SCOPE_FRANCHISE],
                permissions: ['web_management.edit'],
                missing: fn (Franchise $f) => CustomerLinks::branchReviewLink($f) === null,
                fill: fn () => ['kind' => 'open', 'destination' => 'web_management',
                    'hint' => "Open Web Management, then the branch's website, and fill in Review link."],
            ),
            // A Business Profile listing with no branch shows only at the company
            // and organization views, and its reviews and complaints with it.
            new PendingCheck(
                key: 'business_profile.unassigned',
                label: 'Business Profile listings without a branch',
                module: 'business_profile',
                scopes: [SettingDefinition::SCOPE_ORGANIZATION],
                permissions: ['business_profile.edit'],
                missing: fn (Organization $o) => BusinessLocation::query()->where('organization_id', $o->getKey())
                    ->whereNull('franchise_id')->whereNull('not_listed_since')->exists(),
                fill: fn () => ['kind' => 'open', 'destination' => 'customer_experience',
                    'hint' => 'Open Customer Experience, then GBP Listings, and give each listing its branch.'],
            ),
            // Email: a company with no connected inbox, at its own level or the
            // organization's, cannot send or receive from EzBizi.
            new PendingCheck(
                key: 'email.mailbox',
                label: 'Connected email inbox',
                module: 'email',
                scopes: [SettingDefinition::SCOPE_COMPANY],
                permissions: ['email.edit'],
                missing: fn (Company $c) => ! Mailbox::query()
                    ->where('status', Mailbox::STATUS_CONNECTED)
                    ->where(fn ($q) => $q
                        ->where(fn ($q) => $q->where('scope_type', Mailbox::SCOPE_COMPANY)->where('scope_id', $c->id))
                        ->orWhere(fn ($q) => $q->where('scope_type', Mailbox::SCOPE_ORGANIZATION)->where('scope_id', $c->organization_id)))
                    ->exists(),
                fill: fn () => [
                    'kind'        => 'open',
                    'destination' => 'settings',
                    'hint'        => 'Open Settings, then Integrations, then Email.',
                ],
            ),
        ];
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
