<?php

namespace App\Support\Automations;

/**
 * Where the record an event is about sits in the business (plan §4, §5.5).
 *
 * ── Why the engine could not work this out on its own ────────────
 *
 * A franchise-scoped rule must fire on its own franchise's work and on nobody
 * else's. `AutomationEngine::scopeOf` answers that by reading `franchise_id`
 * off the subject, with `owning_franchise_id` as the second guess — which is
 * enough for the two modules the engine was built against, because a Job has
 * the first column and a Customer has the second.
 *
 * Human Resources has neither. An invitation carries §4's XOR — exactly one of
 * `assigned_organization_id`, `assigned_company_id`, `assigned_franchise_id` —
 * and an onboarding carries no scope column at all: where it belongs is the
 * invitation's placement while there is one, and the person's own assignment
 * once they have an account. No amount of guessing at column names finds
 * either, so every HR event would have arrived at the engine claiming no scope,
 * and a franchise-level rule on an onboarding would have matched nothing for
 * the life of the rule with nothing on screen saying why.
 *
 * Adding `assigned_franchise_id` to the list of names to sniff would have fixed
 * one of the two and quietly left company-level invitations wrong — a company
 * invitation has no franchise, and `coversScope` reaches a company through the
 * chain a franchise sits in, so a company-scoped rule would never have covered
 * the invitations sent at exactly its own level.
 *
 * So the record says where it is, rather than the engine guessing. The model is
 * the only place that knows: Onboarding::scope() had already worked it out for
 * §4 reach, and this delegates to it rather than deriving it a second time.
 *
 * Not implementing this is a perfectly good answer, and it is the answer for
 * anything that belongs to the whole organization — a contract template reaches
 * every franchise, so only an organization-wide rule covers a version of one.
 */
interface CarriesAutomationScope
{
    /**
     * @return array{0: string|null, 1: string|null} `'franchise'` or `'company'`
     *         and the id it names, or `[null, null]` when the record belongs to
     *         the whole organization and only an organization-wide rule covers
     *         it. The two words are the ones `automations.scope_type` stores,
     *         because this is the answer that gets compared against it.
     */
    public function automationScope(): array;
}
