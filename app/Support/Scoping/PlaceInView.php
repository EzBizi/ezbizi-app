<?php

namespace App\Support\Scoping;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Filing a record on a place must match the current view, not merely reach.
 *
 * Reach answers "may this person ever act there". The switcher answers "where
 * are they standing now". An administrator who can act on every company still
 * cannot file an A-1 Chimney location on Everdoors while looking at A-1 —
 * that is the same rule MailboxAccess::inView already applies to inboxes.
 */
final class PlaceInView
{
    public static function assert(
        User $user,
        ?Company $company,
        ?Franchise $franchise,
        string $noun,
    ): void {
        if ($company === null && $franchise !== null) {
            throw ValidationException::withMessages([
                'franchise_id' => "A place belongs to a company. File the {$noun} on the company, or leave it on the group as General.",
            ]);
        }

        if ($franchise !== null && $franchise->company_id !== $company->getKey()) {
            throw ValidationException::withMessages([
                'franchise_id' => 'That place is not part of this company.',
            ]);
        }

        $view = ViewScope::forUser($user);

        if ($company === null) {
            if (! $view->isOrganization()) {
                throw ValidationException::withMessages([
                    'company_id' => 'File this on the company you are looking at, not on the group.',
                ]);
            }

            return;
        }

        if (! $view->coversCompany($company->getKey())) {
            throw ValidationException::withMessages([
                'company_id' => 'That company is not in this view.',
            ]);
        }

        if ($franchise === null) {
            if ($view->type === ViewScope::FRANCHISE) {
                throw ValidationException::withMessages([
                    'franchise_id' => 'File this on the place you are looking at.',
                ]);
            }

            return;
        }

        if (! $view->coversFranchise($franchise->getKey())) {
            throw ValidationException::withMessages([
                'franchise_id' => 'That place is not in this view.',
            ]);
        }
    }
}
