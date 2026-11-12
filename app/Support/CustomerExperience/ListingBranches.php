<?php

namespace App\Support\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\SocialConnection;
use Illuminate\Support\Facades\DB;

/**
 * Files Business Profile listings under a branch by their state (Jose, 2026-11-12: "a connect
 * must finish its own setup"). Per company, a state goes to the branch that already holds that
 * company's listings in it, else the branch named for the state ("Texas"), else one whose
 * service areas list it, else the company's "Nationwide". A listing with no address, or a state
 * no branch claims, is left for a person. A connection whose listings all land on one branch
 * gets that branch as its leads branch.
 */
final class ListingBranches
{
    /** Fills the branch on every unfiled listing of the tenant in context; returns [filed, left]. */
    public static function fill(): array
    {
        [$filed, $left] = [0, 0];
        BusinessLocation::query()->whereNull('franchise_id')->get()->each(function (BusinessLocation $l) use (&$filed, &$left) {
            $branch = self::branchFor($l);
            $branch === null ? $left++ : $filed++;
            $branch && $l->forceFill(['franchise_id' => $branch])->save();
        });
        SocialConnection::query()->where('provider', 'google_business_profile')->whereNull('leads_franchise_id')->get()
            ->each(function (SocialConnection $c) {
                $branches = BusinessLocation::query()->where('connection_id', $c->id)->pluck('franchise_id')->unique();
                if ($branches->count() === 1 && $branches->first() !== null) {
                    $c->forceFill(['leads_franchise_id' => $branches->first()])->save();
                }
            });

        return [$filed, $left];
    }

    public static function branchFor(BusinessLocation $l): ?string
    {
        $state = strtoupper(trim((string) $l->state));
        if ($l->company_id === null || ! isset(\App\Support\Social\MetaAdInsights::STATES[$state])) {
            return null;
        }
        $branches = Franchise::query()->where('company_id', $l->company_id)->where('is_active', true)->get(['id', 'name']);

        $held = BusinessLocation::query()->where('company_id', $l->company_id)->where('state', $state)
            ->whereNotNull('franchise_id')->pluck('franchise_id')->unique();
        if ($held->count() === 1) {
            return $held->first();
        }
        $named = $branches->first(fn ($f) => strcasecmp($f->name, \App\Support\Social\MetaAdInsights::STATES[$state]) === 0);
        if ($named) {
            return $named->id;
        }
        $areas = DB::table('service_areas')->whereIn('franchise_id', $branches->pluck('id'))->get(['franchise_id', 'states'])
            ->filter(fn ($a) => in_array($state, (array) (json_decode((string) $a->states, true) ?: explode(',', (string) $a->states)), true))
            ->pluck('franchise_id')->unique();
        if ($areas->count() === 1) {
            return $areas->first();
        }

        return $branches->first(fn ($f) => strcasecmp($f->name, 'Nationwide') === 0)?->id;
    }
}
