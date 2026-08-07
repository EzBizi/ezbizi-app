<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\Franchise;
use App\Models\User;

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
 */
class CustomerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('customers.view');
    }

    public function view(User $user, Customer $customer): bool
    {
        // Same organization is already guaranteed by the tenant scope; asserting
        // it here as well costs nothing and means the policy is still correct if
        // it is ever called outside a scoped query.
        return $user->hasPermission('customers.view')
            && $customer->organization_id === $user->organization_id;
    }

    /** @param  Franchise|null  $owner  the franchise that will be accountable. */
    public function create(User $user, ?Franchise $owner = null): bool
    {
        if (! $user->hasPermission('customers.create')) {
            return false;
        }

        // No owner named yet — this is the "can you open the form" question.
        if ($owner === null) {
            return $user->reachableFranchises()->isNotEmpty();
        }

        return $user->canActOnFranchise($owner);
    }

    /**
     * Editing is org-wide by design (§5.2): "permitted from any franchise, and
     * the audit log records who and from where. The tag creates social clarity;
     * the audit trail creates accountability."
     */
    public function update(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.edit')
            && $customer->organization_id === $user->organization_id;
    }

    /**
     * Moving the accountability.
     *
     * Reach over BOTH ends is required. Over the destination because you are
     * making that franchise answerable for a relationship; over the source
     * because a franchise's customer count is its own number, and someone with
     * no authority there should not be able to reduce it.
     */
    public function reassignOwner(User $user, Customer $customer, Franchise $newOwner): bool
    {
        if (! $user->hasPermission('customers.edit')) {
            return false;
        }

        if ($customer->organization_id !== $user->organization_id) {
            return false;
        }

        return $user->canActOnFranchise($newOwner)
            && $user->canActOnFranchise($customer->owningFranchise);
    }

    /**
     * Deleting is scoped to the owner's reach, unlike editing.
     *
     * Editing a shared record is collaboration; removing one is not something
     * another franchise should be able to do to the franchise accountable for it.
     */
    public function delete(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.delete')
            && $customer->organization_id === $user->organization_id
            && $user->canActOnFranchise($customer->owningFranchise);
    }
}
