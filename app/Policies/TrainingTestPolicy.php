<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\Franchise;
use App\Policies\Concerns\AuthorizesCatalogPlace;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may read and write training tests.
 *
 * `/training/tests` is the AUTHORING surface — the Settings tab, not the
 * Learn tab a learner reads from. Every verb below — read and write alike —
 * checks `training.manage`; `training.view` gates the learner-facing
 * `GET /training/learn` that Task 6 adds instead. One permission, one
 * controller. See TrainingGuidePolicy's docblock — this is byte-identical
 * reasoning, one row over.
 *
 * Mirrors TrainingGuidePolicy and InventoryItemPolicy, verb for verb: General
 * (`franchise_id` null) is visible to anyone who can see that company,
 * including a franchise-only user assigned under it. Organization tests
 * (`company_id` null) are visible throughout the tenant; writing one is an
 * organization act.
 */
class TrainingTestPolicy
{
    use AuthorizesCatalogPlace;

    // Looked up rather than read off a relation: TrainingTest declares no
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
        return 'add a test';
    }

    protected function seeAct(): string
    {
        return 'see this test';
    }

    protected function changeAct(): string
    {
        return 'change a test';
    }

    protected function deleteAct(): string
    {
        return 'delete a test';
    }
}
