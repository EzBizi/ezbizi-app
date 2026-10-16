<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCatalogPlace;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a review, and — separately — who may answer one in public.
 *
 * Files like a service, so the same reach rules apply and a branch manager sees
 * the reviews left on their branch's listing.
 *
 * There is no `create`: nobody writes a review here. Every row is somebody
 * else's public words, fetched from Google. The permission exists because
 * DEFAULT_ACTIONS carries it and the roles screen reads better for the full
 * set; nothing calls it.
 */
class ReviewPolicy
{
    use AuthorizesCatalogPlace;

    protected function permission(string $action): string
    {
        return 'reviews.'.$action;
    }

    protected function addAct(): string
    {
        return 'add a review';
    }

    protected function seeAct(): string
    {
        return 'see this review';
    }

    protected function changeAct(): string
    {
        return 'draft a reply to this review';
    }

    protected function deleteAct(): string
    {
        return 'delete this review';
    }

    /**
     * Publishing is its own permission, and that is the point of this class.
     *
     * `edit` writes a draft nobody outside the building can see. This puts
     * words on Google under the organization's name, in public, permanently.
     * The two are routinely held by different people — whoever writes the reply
     * to an angry review is very often not whoever should decide it goes out —
     * and collapsing them into one grant would make that impossible to express
     * on the Roles screen.
     *
     * It is the same split as `inventory.financials`: a second permission for
     * the half of a module that carries the consequence.
     */
    public function publish(User $user, Review $review): Response
    {
        if (! $user->hasPermission($this->permission('publish'))) {
            return Refusal::permission('publish a reply to a review');
        }

        // Reach is checked as a WRITE, not a read. Seeing a review your branch
        // did not receive is harmless; answering it in the company's name is
        // not.
        return $this->update($user, $review);
    }

    /** Working a review as a case: status, notes, its customer, reporting it. */
    public function manage(User $user, Review $review): Response
    {
        if (! $user->hasPermission($this->permission('manage'))) {
            return Refusal::permission('work on a review');
        }

        // Reach as a write, without also needing `edit`.
        return $this->within($user, $review, 'work on a review', write: true);
    }
}
