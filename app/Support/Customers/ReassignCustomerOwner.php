<?php

namespace App\Support\Customers;

use App\Models\Customer;
use App\Models\Franchise;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Handing a customer to another franchise — the one path that does it.
 *
 * Extracted from CustomersController::reassignOwner so the automation step and
 * the endpoint are the SAME act. The engine already refuses to let a rule set
 * `owning_franchise_id` through "update a field", and says why: reassigning
 * accountability is a separate audited act (§5.2), and a rule must not be able
 * to do silently what an endpoint refuses loudly. A second copy of these lines
 * in the runner would be exactly that door reopened the day this one gained a
 * check the copy did not.
 *
 * AUTHORIZATION IS THE CALLER'S. The endpoint asks the policy about the person
 * making the request; the runner asks it about the rule's author. Both ask the
 * same policy before they get here.
 */
final class ReassignCustomerOwner
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /**
     * @throws InvalidArgumentException when that franchise already owns it
     */
    public function __invoke(Customer $customer, Franchise $newOwner, ?string $reason = null): void
    {
        if ($newOwner->is($customer->owningFranchise)) {
            throw new InvalidArgumentException('That franchise already owns this customer.');
        }

        $previous = $customer->owningFranchise;

        DB::transaction(function () use ($customer, $newOwner, $previous, $reason) {
            $customer->owning_franchise_id = $newOwner->getKey();
            $customer->save();

            // Names as well as ids: the franchise may be renamed or deactivated
            // later, and "who was accountable in March" has to stay answerable.
            $this->audit->log('customer.owner_reassigned', $customer, metadata: [
                'from'   => ['id' => $previous?->getKey(), 'name' => $previous?->name],
                'to'     => ['id' => $newOwner->getKey(), 'name' => $newOwner->name],
                'reason' => $reason,
            ]);

            $this->events->emit('customer.owner_reassigned', $customer, [
                'before' => ['owning_franchise_id' => $previous?->getKey()],
                'after'  => ['owning_franchise_id' => $newOwner->getKey()],
            ]);
        });
    }
}
