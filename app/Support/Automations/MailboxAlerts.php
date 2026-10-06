<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The "Notify about new emails" switch in Email settings, kept in step with
 * the rule that does it (Jose, 2026-10-06: "to be changed on the automation
 * ofc ... a link on email settings, so it can be changed from there as well
 * like syncing with the automation").
 *
 * THE RULE IS THE ONE SOURCE OF TRUTH. Nothing marks it: it is the one
 * email.received rule (not deleted; on or off) that has a notify step. Its
 * mailboxes are a top-level `address` "is one of" clause. The switch edits
 * that list through Automation::addVersion, the builder's own writer, so
 * every flip is a version with an author.
 *
 * NEVER A GUESS. More than one such rule, or one hand-edited out of that
 * shape, makes the switch read-only with a reason; the rule is then edited in
 * Automations.
 */
final class MailboxAlerts
{
    public const TRIGGER = 'email.received';

    /** Agreed with Email (2026-10-06): mail a person wrote, not bulk. */
    private const NOT_BULK = ['field' => 'is_bulk', 'op' => 'eq', 'value' => false];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{rule_id: ?string, addresses: list<string>, editable: bool, reason: ?string}
     */
    public function state(): array
    {
        [$rule, $reason] = $this->find();

        if ($reason !== null) {
            return ['rule_id' => $rule?->id, 'addresses' => [], 'editable' => false, 'reason' => $reason];
        }

        return [
            'rule_id' => $rule?->id,
            // A switched-off rule notifies nobody, so no mailbox reads as on.
            'addresses' => $rule !== null && $rule->is_active ? $this->addressesOf($rule) : [],
            'editable' => true,
            'reason' => null,
        ];
    }

    public function set(string $address, bool $on, User $actor): void
    {
        $address = mb_strtolower(trim($address));

        DB::transaction(function () use ($address, $on, $actor) {
            [$rule, $reason] = $this->find();

            if ($reason !== null) {
                throw new RuntimeException($reason);
            }

            if ($rule === null) {
                if ($on) {
                    $this->create($address, $actor);
                }

                return;
            }

            // Off rule, switch turned on: start again from this one mailbox
            // rather than reviving addresses somebody turned off before.
            $addresses = $rule->is_active ? $this->addressesOf($rule) : [];
            $addresses = $on
                ? array_values(array_unique([...$addresses, $address]))
                : array_values(array_diff($addresses, [$address]));

            $version = $rule->activeVersion;
            $conditions = $version->conditions;
            foreach ($conditions['all'] as $i => $clause) {
                if (($clause['field'] ?? null) === 'address') {
                    $conditions['all'][$i]['value'] = $addresses;
                }
            }

            $rule->addVersion($conditions, $version->actions, $actor);

            // The last mailbox off switches the rule off: an empty "one of"
            // would sit there matching nothing.
            $rule->forceFill($addresses === []
                ? ['is_active' => false]
                : ['is_active' => true, 'disabled_at' => null, 'disabled_reason' => null, 'consecutive_failures' => 0])->save();

            $this->audit->log('automation.updated', $rule, metadata: [
                'via' => 'email settings', 'mailbox' => $address, 'on' => $on,
            ]);
        });
    }

    /** @return array{0: ?Automation, 1: ?string} the rule, and why it cannot be edited here */
    private function find(): array
    {
        $rules = Automation::query()->where('trigger_key', self::TRIGGER)->with('activeVersion')->get()
            ->filter(fn (Automation $a) => collect($a->activeVersion?->actions ?? [])->contains('type', 'notify'))
            ->values();

        if ($rules->count() > 1) {
            return [null, 'More than one automation notifies about new email. Change them in Automations.'];
        }

        $rule = $rules->first();

        if ($rule !== null && $this->addressClauseOf($rule) === null) {
            return [$rule, 'This was changed in Automations. Edit it there.'];
        }

        return [$rule, null];
    }

    private function addressClauseOf(Automation $rule): ?array
    {
        $conditions = $rule->activeVersion?->conditions;

        if (! is_array($conditions) || array_keys($conditions) !== ['all'] || ! is_array($conditions['all'])) {
            return null;
        }

        $clauses = array_values(array_filter($conditions['all'], fn ($c) => ($c['field'] ?? null) === 'address'));

        return count($clauses) === 1 && ($clauses[0]['op'] ?? null) === 'in' && is_array($clauses[0]['value'] ?? null)
            ? $clauses[0]
            : null;
    }

    /** @return list<string> */
    private function addressesOf(Automation $rule): array
    {
        return array_values(array_map(fn ($a) => mb_strtolower((string) $a), $this->addressClauseOf($rule)['value'] ?? []));
    }

    /**
     * Jose's rule (2026-10-06): sender and subject, then the first 178
     * characters, to the same people as new leads. Refused rather than sent
     * to everybody when that audience cannot be read.
     */
    private function create(string $address, User $actor): void
    {
        $leadRules = Automation::query()->where('trigger_key', 'lead.received')->where('is_active', true)
            ->with('activeVersion')->get()
            ->map(fn (Automation $a) => collect($a->activeVersion?->actions ?? [])->firstWhere('type', 'notify'))
            ->filter()->values();

        if ($leadRules->count() !== 1 || ($leadRules[0]['role_ids'] ?? []) === []) {
            throw new RuntimeException('Who should be told is taken from your new-lead notification, and there is not '
                .'exactly one. Create this rule in Automations.');
        }

        $rule = Automation::create([
            'name' => 'New email',
            'description' => 'Sender and subject, then the start of the message, for mail a person wrote. '
                .'Mailboxes are switched on and off from Email settings too.',
            'trigger_key' => self::TRIGGER,
            'is_active' => true,
            'created_by' => $actor->getKey(),
        ]);

        $rule->addVersion(
            ['all' => [['field' => 'address', 'op' => 'in', 'value' => [$address]], self::NOT_BULK]],
            [['type' => 'notify', 'message' => '{{from_name}}: {{subject}}', 'details' => '{{description}}',
                'role_ids' => array_values($leadRules[0]['role_ids'])]],
            $actor,
        );

        $this->audit->log('automation.created', $rule, metadata: ['via' => 'email settings', 'mailbox' => $address]);
    }
}
