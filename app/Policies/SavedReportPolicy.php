<?php

namespace App\Policies;

use App\Policies\Concerns\AuthorizesCatalogPlace;

/**
 * Who may do what to a saved report (spec 2026-09-10).
 *
 * `view` opens the tab and runs what you have been given. `build` composes,
 * edits and deletes — one permission for all three, because they are one act
 * and because the disclosure that matters is composing: the field picker
 * offers every mapped column, money included, while running a saved report
 * returns only what its author already chose.
 *
 * So the trait's create / edit / delete all map to `build`, and only `view`
 * maps to `view`. That is a deliberate collapse rather than a missing pair of
 * permissions: an organization that wants somebody to run reports but not
 * write them grants `reports.view` alone, which is the common case.
 */
class SavedReportPolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'reports.'.($action === 'view' ? 'view' : 'build');
    }

    protected function addAct(): string
    {
        return 'build a report';
    }

    protected function seeAct(): string
    {
        return 'see this report';
    }

    protected function changeAct(): string
    {
        return 'change this report';
    }

    protected function deleteAct(): string
    {
        return 'delete this report';
    }
}
