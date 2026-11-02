<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Policies\Concerns\AuthorizesCatalogPlace;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may read and write training guides.
 *
 * `/training/guides` is the AUTHORING surface — the Settings tab, not the
 * Learn tab a learner reads from. The spec's tab table maps Settings to
 * `manage`, Learn to `view`, Dashboard to `monitor`; gating reads here on
 * `training.view` would hand every guide, drafts included, to anyone who
 * merely holds Learn access. So every verb below — read and write alike —
 * checks `training.manage`, and `training.view` gates the learner-facing
 * `GET /training/learn` that Task 6 adds instead. One permission, one
 * controller.
 *
 * Mirrors InventoryItemPolicy, verb for verb, past the permission check:
 * General (`franchise_id` null) is visible to anyone who can see that
 * company, including a franchise-only user assigned under it. Organization
 * guides (`company_id` null) are visible throughout the tenant; writing one
 * is an organization act.
 */
class TrainingGuidePolicy
{
    use AuthorizesCatalogPlace;

    // Looked up rather than read off a relation: TrainingGuide declares no
    // `company()` or `franchise()` (see the model).
    protected function companyOf(Model $row): ?Company
    {
        return Company::find($row->company_id);
    }

    protected function franchiseOf(Model $row): ?Franchise
    {
        return Franchise::find($row->franchise_id);
    }

    protected function permission(string $action): string
    {
        return 'training.manage';
    }

    protected function seeAnyAct(): string
    {
        return 'see training';
    }

    protected function addAct(): string
    {
        return 'add a guide';
    }

    protected function seeAct(): string
    {
        return 'see this guide';
    }

    protected function changeAct(): string
    {
        return 'change a guide';
    }

    protected function deleteAct(): string
    {
        return 'delete a guide';
    }
}
