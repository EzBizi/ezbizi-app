<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Who may do what to a customer (plan §4, §5.2).
 *
 * Customers are visible org-wide, which changes the shape of this from every
 * other policy in the system: reading and editing are permission questions
 * only, because a customer served by two franchises is one record both can
 * work with.
 *
 * Reach still matters in two places, and both are about ACCOUNTABILITY rather
 * than access:
 *
 *   - creating one, because you are naming a franchise that will be answerable
 *     for the relationship and will count it in their numbers
 *   - reassigning the owner, for the same reason, in both directions
 *
 * Note what is deliberately absent: a franchise check on view or update. That
 * follows from the hierarchy (§3), not from a preference anyone can set. A
 * customer is entered against a franchise, the franchise sits inside a company,
 * and the company sits inside the organization — so the customer is the
 * organization's, and seeing it from the organization view is the same record
 * seen from further up its own tree. Adding a franchise check here would refuse
 * an organization its own data on the strength of which branch typed it in.
 *
 * (There was once a `shares_customers` column suggesting this could be switched
 * off per tenant. Nothing ever read it and it has been dropped — what keeps the
 * relationship unambiguous is the owning-franchise tag, which is visible
 * wherever the customer appears, and the audit trail behind every edit.)
 *
 * ── Why the refusals carry sentences ─────────────────────────────
 *
 * Precisely because the rules above are not obvious. "You may edit any
 * customer in the business but may only delete your own franchise's" is a
 * deliberate asymmetry, and a bare 403 on the delete makes it look like the
 * edit that just worked was the accident. See App\Support\Access\Refusal.
 */
class CustomerPolicy
{
    public function viewAny(User $user): Response
    {
        return $user->hasPermission('customers.view')
            ? Response::allow()
            : Refusal::permission('see customers');
    }

    public function view(User $user, Customer $customer): Response
    {
        // Same organization is already guaranteed by the tenant scope; asserting
        // it here as well costs nothing and means the policy is still correct if
        // it is ever called outside a scoped query.
        if ($customer->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission('customers.view')
            ? Response::allow()
            : Refusal::permission('see customers');
    }

    /** @param  Franchise|null  $owner  the franchise that will be accountable. */
    public function create(User $user, ?Franchise $owner = null): Response
    {
        if (! $user->hasPermission('customers.create')) {
            return Refusal::permission('add a customer');
        }

        // No owner named yet — the "can you open the form" question. Nothing
        // asks it today; see the same branch in JobPolicy::create for why it is
        // still here and why it does not answer `true`.
        if ($owner === null) {
            return $user->reachableFranchises()->isNotEmpty()
                ? Response::allow()
                : Refusal::nowhereToAct($user, Refusal::AT_FRANCHISE, 'add a customer');
        }

        return $user->canActOnFranchise($owner)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_FRANCHISE,
                'add a customer',
                $owner->name,
                'It would be accountable for this relationship and would count it '
                .'in its own numbers, which is why it has to be somewhere you stand.'
            );
    }

    /**
     * Editing is org-wide by design (§5.2): "permitted from any franchise, and
     * the audit log records who and from where. The tag creates social clarity;
     * the audit trail creates accountability."
     */
    public function update(User $user, Customer $customer): Response
    {
        if ($customer->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        return $user->hasPermission('customers.edit')
            ? Response::allow()
            : Refusal::permission('change a customer');
    }

    /**
     * Moving the accountability.
     *
     * Reach over BOTH ends is required. Over the destination because you are
     * making that franchise answerable for a relationship; over the source
     * because a franchise's customer count is its own number, and someone with
     * no authority there should not be able to reduce it.
     *
     * Both halves are named separately when they fail, because "you cannot move
     * this customer" is useless when the reader has authority over one end and
     * is looking straight at it.
     */
    public function reassignOwner(User $user, Customer $customer, Franchise $newOwner): Response
    {
        if ($customer->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('customers.edit')) {
            return Refusal::permission('move a customer to another '
                .strtolower($user->organization?->franchise_label ?? 'franchise'));
        }

        if (! $user->canActOnFranchise($newOwner)) {
            return Refusal::noReach(
                $user, Refusal::AT_FRANCHISE, 'hand this customer over', $newOwner->name,
            );
        }

        return $user->canActOnFranchise($customer->owningFranchise)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_FRANCHISE,
                'hand this customer on',
                $customer->owningFranchise->name,
                'That is who is accountable for it today, and a customer count is '
                .'that part of the business’s own number to lose.'
            );
    }

    /**
     * Deleting is scoped to the owner's reach, unlike editing.
     *
     * Editing a shared record is collaboration; removing one is not something
     * another franchise should be able to do to the franchise accountable for it.
     */
    public function delete(User $user, Customer $customer): Response
    {
        if ($customer->organization_id !== $user->organization_id) {
            return Response::denyAsNotFound();
        }

        if (! $user->hasPermission('customers.delete')) {
            return Refusal::permission('delete a customer');
        }

        return $user->canActOnFranchise($customer->owningFranchise)
            ? Response::allow()
            : Refusal::noReach(
                $user,
                Refusal::AT_FRANCHISE,
                'delete this customer',
                $customer->owningFranchise->name,
                'Any part of the business may CHANGE a shared customer — that is '
                .'collaboration. Removing one is not something to be able to do to '
                .'whoever is accountable for it.'
            );
    }
}
