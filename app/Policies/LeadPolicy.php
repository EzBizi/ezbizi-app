<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCatalogPlace;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may do what to a lead.
 *
 * Files the same way a service does — organization, company, or franchise —
 * so the same reach rules apply and a branch manager sees the enquiries their
 * branch's advertising produced rather than the whole group's.
 *
 * There is no `create`: nobody types a lead in. Every row arrives from a
 * connected advertising account, which is what makes it evidence of what a
 * member of the public actually asked for. The permission exists because
 * DEFAULT_ACTIONS carries it and the roles screen is clearer for having the
 * full set; nothing calls it.
 */
class LeadPolicy
{
    use AuthorizesCatalogPlace {
        view as reachMayView;
        update as reachMayUpdate;
        delete as reachMayDelete;
    }

    /*
     * THE SOURCE IS A SEPARATE GRANT - Jose, 2026-09-17.
     *
     * Gated here rather than only in the controller's list because a lead has
     * more doors than the list: show, star, note, status, reply. A filter on
     * the index alone would hide a Thumbtack enquiry from a person and still
     * let them reply to it by pasting the id, which is the shape the inbox
     * brief calls a decoration rather than a permission.
     *
     * Read-then-write, in that order: somebody who cannot SEE the source
     * cannot change it either, so the same check sits in front of all three.
     */
    public function view(User $user, Model $row): Response
    {
        return $this->ofAVisibleSource($user, $row) ?? $this->reachMayView($user, $row);
    }

    public function update(User $user, Model $row): Response
    {
        return $this->ofAVisibleSource($user, $row) ?? $this->reachMayUpdate($user, $row);
    }

    public function delete(User $user, Model $row): Response
    {
        return $this->ofAVisibleSource($user, $row) ?? $this->reachMayDelete($user, $row);
    }

    /**
     * Null when the source is allowed, so the caller falls through to reach.
     *
     * Refuses as NOT FOUND rather than as a permission, deliberately: a person
     * who may not see Thumbtack enquiries should not learn that this id is one.
     * The same reasoning the trait already applies to another organization's
     * rows one method down.
     */
    private function ofAVisibleSource(User $user, Model $row): ?Response
    {
        if (! $row instanceof Lead) {
            return null;
        }

        // A hidden lead is not found for anyone without the Hidden grant (Jose, 2026-10-29).
        return in_array($row->source, Lead::sourcesVisibleTo($user), true)
            && (! $row->isHidden() || $user->hasPermission(Lead::HIDDEN_PERMISSION))
            ? null
            : Response::denyAsNotFound();
    }

    protected function permission(string $action): string
    {
        return 'leads.'.$action;
    }

    protected function addAct(): string
    {
        return 'add a lead';
    }

    protected function seeAct(): string
    {
        return 'see this lead';
    }

    protected function changeAct(): string
    {
        return 'change this lead';
    }

    protected function deleteAct(): string
    {
        return 'delete this lead';
    }
}
