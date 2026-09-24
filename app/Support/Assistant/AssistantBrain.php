<?php

namespace App\Support\Assistant;

use App\Models\AssistantEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebValue;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Collection;

/**
 * What the assistant knows, and who may read which part of it.
 *
 * One brain, two mouths: WhatsAppStaffBot draws it as WhatsApp lists and text,
 * AssistantController as JSON for the app - Jose, 2026-09-24: "so we can also
 * have the same inside the app for people who dont have whatsapp". Neither may
 * decide visibility for itself, or the two would drift and one of them would
 * show a customer what the company pays each lender.
 *
 * ── Who is reading ───────────────────────────────────────────────
 *
 * `$viewer` is a User who works here, or null for anybody else. A viewer
 * reads the organization's answers plus those of every company and franchise
 * they reach - the same place engine Training and Inventory use - in both
 * audiences. Nobody-in-particular reads only organization-level PUBLIC answers:
 * a place's answer belongs to the people of that place, and a staff answer to
 * staff.
 *
 * Reach is the user's whole reach, never their saved view: a tech on WhatsApp
 * has no view switcher, and the app should answer them the same.
 */
final class AssistantBrain
{
    public function __construct(private readonly SettingsResolver $settings) {}

    /** @return Collection<int, AssistantEntry> in menu order */
    public function entries(?User $viewer): Collection
    {
        $query = AssistantEntry::query();

        if ($viewer === null) {
            $query->whereNull('company_id')->where('audience', AssistantEntry::AUDIENCE_PUBLIC);
        } else {
            InventoryVisibility::constrain($query, $viewer, ViewScope::reachOf($viewer));
        }

        return $query->orderBy('position')->orderBy('title')->get();
    }

    /**
     * The menu: each section once, in the order of its first answer.
     *
     * @return Collection<string, Collection<int, AssistantEntry>> section => its answers
     */
    public function sections(?User $viewer): Collection
    {
        return $this->entries($viewer)->groupBy('section');
    }

    /**
     * One answer, only if this viewer may read it. Null for an id that does not
     * exist and for one that is not theirs alike, so a tapped id cannot be used
     * to learn that a hidden answer is there.
     */
    public function entry(?User $viewer, string $id): ?AssistantEntry
    {
        return $this->entries($viewer)->firstWhere('id', $id);
    }

    public function name(Organization $organization): string
    {
        return (string) $this->settings->get('ai_assistance.name', $organization);
    }

    public function welcome(Organization $organization): string
    {
        return (string) $this->settings->get('ai_assistance.welcome', $organization);
    }

    /**
     * Sites whose city, state or name matches, with the facts a tech needs.
     *
     * READ, never copied: from the sites Web Management holds, at the moment
     * somebody asks. A list typed in anywhere else would be right the day it
     * was written and wrong the day a branch changed its number. Under the
     * tenant scope, so only ever this organization's sites.
     *
     * @return Collection<int, array<string, string>>
     */
    public function locations(Organization $organization, string $query): Collection
    {
        $q = trim($query);

        /*
         * SHORT QUERIES MATCH WHOLE, NEVER AS A SUBSTRING.
         *
         * Measured against production, 2026-09-24. A tech typing a branch code
         * - "CHI" - substring-matched the company NAME, and "chi" is inside
         * "Chimney", so it matched all 43 A1 Chimney sites and the real Chicago
         * branch drowned among them. A state has the same trap in addresses:
         * "oh" is inside "John". So three characters or fewer is a code or a
         * state, matched exactly: `state`, or the branch code after " - ".
         */
        if (mb_strlen($q) <= 3) {
            $ids = WebValue::query()
                ->where('path', '')
                ->where(fn ($w) => $w
                    ->where(fn ($x) => $x->where('key', 'state')->where('value', $q))
                    ->orWhere(fn ($x) => $x->where('key', 'company_name')->where(fn ($y) => $y
                        ->where('value', 'like', '% - '.$q)
                        ->orWhere('value', 'like', '% - '.$q.' - %'))))
                ->distinct()
                ->pluck('site_id');
        } else {
            /*
             * A CITY LIVES IN `service_area`, NOT IN `city`. Measured:
             * Cleveland's branch has `city` = "Warrensville Heights" and
             * `service_area` = "Cleveland, Ohio". The word a tech types is the
             * one the site SERVES. The address is searched too, for a street
             * or a suburb somebody remembers.
             */
            $like = '%'.addcslashes($q, '%_\\').'%';

            $ids = WebValue::query()
                ->where('path', '')
                ->whereIn('key', ['service_area', 'city', 'address'])
                ->where('value', 'like', $like)
                ->distinct()
                ->pluck('site_id');
        }

        /*
         * One brand only, when the organization names one. A-1 also runs
         * Everdoors and A1 Garage Door, which surfaced in the same search; a
         * chimney tech handed a garage-door number calls the wrong business.
         * `group` is the Web Management field that says which brand a site is.
         */
        $group = trim((string) $this->settings->get('ai_assistance.location_group', $organization));

        if ($group !== '') {
            $ids = $ids->intersect(WebValue::query()
                ->where('path', '')
                ->where('key', 'group')
                ->where('value', $group)
                ->pluck('site_id'));
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        return WebValue::query()
            ->where('path', '')
            ->whereIn('site_id', $ids)
            ->whereIn('key', ['company_name', 'phone', 'address', 'service_area', 'maps_url'])
            ->get(['site_id', 'key', 'value'])
            ->groupBy('site_id')
            ->map(fn (Collection $rows) => $rows->pluck('value', 'key')->all())
            // An ad landing page duplicates its branch - same phone, same
            // area - so a tech would see every such branch twice.
            ->reject(fn (array $site) => (bool) preg_match('/-\s*ads\s*$/i', $site['company_name'] ?? ''))
            // A site with no phone and no address has nothing to tell a tech.
            ->filter(fn (array $site) => ($site['phone'] ?? '') !== '' || ($site['address'] ?? '') !== '')
            ->sortBy(fn (array $site) => $site['company_name'] ?? '')
            ->values();
    }
}
