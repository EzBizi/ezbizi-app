<?php

namespace App\Support\Reports;

use App\Models\Franchise;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;

/**
 * What the signed-in reader's current view covers, for Reports (Jose,
 * 2026-10-19: "im on everdoors and it's showing all the a-1chimney info"). A
 * company view sees that company's branches, a branch view that branch; the
 * organization view, and a run with nobody signed in (scheduled texts), sees
 * everything.
 */
final class ReportView
{
    /**
     * The organization's own time zone, where a report's days start and end
     * (Jose, 2026-11-04: "Yesterday" held the evening before, cut at UTC
     * midnight). The app's zone only with nobody signed in or none set.
     */
    public static function zone(): string
    {
        $org = auth()->user()?->organization;

        return (string) (($org === null ? null : app(SettingsResolver::class)->get('timezone', $org)) ?: config('app.timezone'));
    }

    /** @return list<string>|null the branches in view; null is the whole organization */
    public static function franchiseIds(): ?array
    {
        $view = self::view();

        return $view === null || $view->isOrganization() ? null : array_values($view->franchiseIds());
    }

    /**
     * Does the view cover this company: the company itself, or a branch of
     * it? Null (the organization's own) only the organization view covers.
     */
    public static function coversCompany(?string $companyId): bool
    {
        $view = self::view();

        if ($view === null || $view->isOrganization()) {
            return true;
        }

        return $companyId !== null && ($view->coversCompany($companyId)
            || Franchise::query()->whereIn('id', $view->franchiseIds())->where('company_id', $companyId)->exists());
    }

    private static function view(): ?ViewScope
    {
        $user = auth()->user();

        return $user === null ? null : ViewScope::forUser($user);
    }
}
