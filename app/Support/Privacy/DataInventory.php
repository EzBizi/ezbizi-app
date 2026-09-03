<?php

namespace App\Support\Privacy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Organization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Everywhere this organization's personal data is, and why (§9).
 *
 * ── What this is for ─────────────────────────────────────────────
 *
 * §9 puts a data inventory in the "build now" list beside export and purge, and
 * gives the reason: it is "impossible to reconstruct later; trivial to maintain
 * as you go". The HR plan sharpens it — once a tenant defines their own fields,
 * an erasure request cannot be answered at all without one, because nobody
 * except the tenant knows that the field they called `acct` is a bank account.
 *
 * It is deliberately SMALL. A derived listing, not a subsystem: no table, no
 * job, no admin screen of its own. Everything in it is read from the models
 * themselves at the moment somebody asks.
 *
 * ── Derived, so it cannot quietly go stale ───────────────────────
 *
 * The models are found by looking in app/Models rather than by consulting a
 * list. A hand-kept list has exactly one failure mode and it is fatal to the
 * purpose: somebody adds a table full of personal data, does not think about
 * the inventory, and the document that is supposed to be the complete answer
 * confidently omits it. Discovery means the only way to be missing from here is
 * to not implement the interface — which is a decision somebody makes on the
 * model, in front of the columns they are adding.
 *
 * ── What it does NOT claim ───────────────────────────────────────
 *
 * It covers this application's own database. §9 lists several places personal
 * data also lives that no amount of model-scanning will find — backups (which
 * cannot be rewritten), the nginx access log, anything shipped off the box. Those
 * belong in the written notice rather than in a query, and `caveats()` says so
 * in the response rather than leaving the reader to assume the list is total.
 * An inventory believed to be complete when it is not is worse than none.
 */
class DataInventory
{
    public function __construct(
        private readonly TenantContext $tenant,
        /*
         * The walk of app/Models moved to DeclaringModels when the purge and the
         * retention sweep needed the same one. Three copies of a glob is three
         * places for the condition to drift — and the condition DID differ:
         * this asks for HoldsPersonalData, the sweep asks for the strictly larger
         * StatesRetention, and two hand-written loops would have looked identical.
         */
        private readonly DeclaringModels $models,
    ) {}

    /**
     * The inventory for one organization, with counts.
     *
     * Counts are what turn a schema description into an answer: "we may hold
     * audit entries about you" and "we hold 41,208 of them" are different
     * statements to somebody asking what you have. They are read inside the
     * organization's own tenant context, so a count can never be another
     * customer's.
     */
    public function forOrganization(Organization $organization): array
    {
        return $this->tenant->run($organization, function () use ($organization) {
            $holdings = [];

            foreach ($this->models->holdingPersonalData() as $class) {
                /** @var Model $model */
                $model = new $class;

                $holdings[] = [
                    'model'   => class_basename($class),
                    'table'   => $model->getTable(),
                    'label'   => Str::headline(class_basename($class)),
                    'purpose' => $class::personalDataPurpose(),
                    // The sentence AND the machine-readable policy behind it, so
                    // a reader can see that "3 months" is a thing something acts
                    // on rather than a number somebody typed into prose.
                    'retention' => $class::retention()->sentence(),
                    'retention_policy' => $class::retention()->toArray(),
                    'fields'  => array_map(
                        fn (PersonalDataEntry $e) => $e->toArray(),
                        $class::personalDataHeld(),
                    ),
                    // Whether erasing this is a delete or an anonymise-in-place.
                    // §9's resolution of the soft-delete tension turns on exactly
                    // this distinction, and getting it wrong in either direction
                    // is a legal problem: a hard delete of an invoice breaks tax
                    // law, and a soft delete of personal data is retained
                    // personal data pretending to be gone.
                    'soft_deleted' => in_array(
                        SoftDeletes::class, class_uses_recursive($class), true,
                    ),
                    'rows' => $this->countFor($class),
                ];
            }

            return [
                'organization' => ['id' => $organization->getKey(), 'name' => $organization->name],
                'generated_at' => now()->toIso8601String(),
                'holdings'     => $holdings,
                'caveats'      => $this->caveats(),
            ];
        });
    }

    /**
     * The places this listing cannot see.
     *
     * Published with the inventory rather than kept in somebody's head, because
     * the failure mode of an inventory is being trusted as complete. Each of
     * these is named in §9 as somewhere personal data hides.
     *
     * @return string[]
     */
    public function caveats(): array
    {
        return [
            'Backups hold whatever was live when they were taken and cannot be rewritten. '
                .'Erasure applies to live systems; backups age out on their own cycle, and a '
                .'restore has to re-apply any erasure made since.',
            'Web server access logs record IP addresses and request lines, and are outside '
                .'this application.',
            'Anything exported by a person to their own computer has left this system, and '
                .'nothing here can enumerate or erase it. Exports of sensitive values are '
                .'refused by default for that reason.',
            'Sign-in sessions record the IP address and browser of each device. They hang off '
                .'the account rather than the organization, so the count above is reached '
                .'through the accounts in it; they are listed in full to the person they '
                .'belong to under "Your account", and they go when the account does.',
            /*
             * The web session table, named because it is the one place in this
             * schema that holds an IP address nothing above can account for.
             *
             * It is not in the listing and cannot usefully be: the API
             * authenticates with bearer tokens, so `sessions.user_id` is null on
             * every row, and there is no person to attach one to or to erase it
             * for. Saying so is the answer; leaving it out of both the listing
             * and the caveats would be the inventory quietly omitting an address.
             */
            'A visitor to the public page gets an anonymous browser session, which records '
                .'an IP address and a browser against no account at all. Signing in does not '
                .'use one — the application authenticates with tokens — so those rows cannot '
                .'be tied to a person, listed under anybody\'s name, or erased on request. '
                .'They expire on the session lifetime.',
        ];
    }

    /**
     * Rows of this kind belonging to the organization in context — unless the
     * model says the organization is the wrong unit for it, which it does by
     * declaring `narrowToOrganization`.
     *
     * Soft-deleted rows are counted. They are still personal data being held —
     * that is §9's whole point about the tension between soft delete and erasure
     * — and an inventory that quietly omitted them would understate exactly the
     * category somebody is most likely to be asking about.
     */
    private function countFor(string $class): int
    {
        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        /*
         * A model that knows better than the organization gets asked FIRST.
         *
         * Not every table that holds personal data holds an organization_id.
         *
         * `personal_access_tokens` is the one that found this: it carries an IP
         * address and a user agent per session — a record of where somebody has
         * been — but it belongs to a USER, and reaches an organization only
         * through them. Assuming the column threw
         * "Unknown column 'organization_id'" and took the whole inventory
         * endpoint down with it.
         *
         * So a model that cannot be scoped by column says how it can be, and
         * one that can neither carry the column nor answer this is left out of
         * the counts rather than crashing the report — with its name in the
         * response, because "we hold this and cannot tell you how much" is a
         * true answer and silence is not.
         *
         * WHY THIS IS ABOVE THE TENANT BRANCH, and not below it as it was.
         *
         * Behind that branch the hook was unreachable for every tenant-scoped
         * model, which made it look like an answer to one question — "how do I
         * reach an organization from a table with no column for it" — when it
         * is the answer to the more general one: "what is the right count for
         * this table". `Suggestion` is the case that proves the difference. It
         * IS tenant-scoped, so the branch below counted it correctly for the
         * organization and incorrectly for the READER: the module exists so
         * that a colleague and the org's own administrator cannot see what
         * somebody wrote, and an organization-wide `rows:` printed directly
         * under that promise tells an administrator of a three-person company
         * that a suggestion was sent, when, and roughly by whom. The audit-row
         * exemption on that model closes the same leak through the other door;
         * this is the door nobody checked.
         *
         * A model that narrows differently must therefore be able to say so
         * whether or not it carries the column — so the hook is asked first,
         * and the branches below are the defaults for models that stay silent.
         */
        if (method_exists($class, 'narrowToOrganization')) {
            return $class::narrowToOrganization($query, $this->tenant->idOrFail())->count();
        }

        /*
         * The audit log is the model this branch exists for.
         *
         * It carries `organization_id` and deliberately has no tenant scope: it
         * is written in platform scope too, by staff acting across customers
         * (§4.2), and a global scope would make those writes unreadable to the
         * code that made them. So the predicate is spelt out here rather than
         * assumed — without it this would count every organization's entries
         * and report the total as one customer's.
         */
        if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            return $query->count();          // the global scope has already done it
        }

        if (! Schema::hasColumn((new $class)->getTable(), 'organization_id')) {
            return -1;
        }

        return $query->where('organization_id', $this->tenant->idOrFail())->count();
    }
}
