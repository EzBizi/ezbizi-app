<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use App\Models\WebSite;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may register a website and set what it publishes.
 *
 * Two permissions, matching the module: `web_management.view` reads the sites
 * and their values, `web_management.edit` registers a host, rotates its token
 * and changes a value. Removing a site is `edit` rather than a `delete` of its
 * own — see the module declaration for why that switch does not exist.
 *
 * ── Company reach, and deliberately not franchise reach ──
 *
 * A site belongs to a company, so acting on one asks `canActOnCompany`. A
 * franchise-only user does not qualify even for the company their branch sits
 * under: the domain is the brand's, one host serves every branch, and the
 * phone number that changes is the one every visitor to that site sees. Giving
 * one branch the pen would let it rewrite the company's front door. If New
 * Jersey needs its own answer it gets its own subdomain, which is its own site.
 */
class WebSitePolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('web_management.view')
            ? Response::allow()
            : Refusal::permission('see websites');
    }

    public function view(User $user, WebSite $site): Response
    {
        if (! $user->hasPermission('web_management.view')) {
            return Refusal::permission('see websites');
        }

        return $this->atCompany($user, $site->company, 'see this website');
    }

    public function create(User $user, Company $company): Response
    {
        if (! $user->hasPermission('web_management.edit')) {
            return Refusal::permission('add a website');
        }

        return $this->atCompany($user, $company, 'add a website');
    }

    public function update(User $user, WebSite $site): Response
    {
        if (! $user->hasPermission('web_management.edit')) {
            return Refusal::permission('change a website');
        }

        return $this->atCompany($user, $site->company, 'change this website');
    }

    public function delete(User $user, WebSite $site): Response
    {
        if (! $user->hasPermission('web_management.edit')) {
            return Refusal::permission('remove a website');
        }

        return $this->atCompany($user, $site->company, 'remove this website');
    }

    private function atCompany(User $user, ?Company $company, string $act): Response
    {
        if ($company === null) {
            return Response::deny('That website is not attached to a company.');
        }

        return $user->canActOnCompany($company)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_COMPANY, $act, $company->name);
    }
}
