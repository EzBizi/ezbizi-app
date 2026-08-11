<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * What a refusal says (plan §4, §8; R8).
 *
 * ── Why a shared place rather than a sentence per policy ─────────
 *
 * A policy that returns `false` produces "This action is unauthorized." — the
 * framework's words, not the product's. It names no permission, no place and no
 * remedy, so the person reading it cannot tell whether they asked for the wrong
 * thing, asked in the wrong place, or are simply missing a grant somebody can
 * hand them in ten seconds.
 *
 * There are only ever THREE things gone wrong:
 *
 *   - the role does not hold the permission                  → [permission]
 *   - it holds it, but not over that place (§4 reach)        → [noReach]
 *   - the act is the whole organization's to make            → [abovePosition]
 *
 * Written once per policy those become one slightly different sentence per
 * policy, and the reach one in particular is the one people get wrong: "you do
 * not have permission" is the WRONG thing to tell somebody who has the
 * permission and no assignment, because the fix is an assignment and they will
 * go and ask for a permission they already hold.
 *
 * ── What every policy shares, and what it does not ───────────────
 *
 * [permission] is shared by all ten policies that refuse with a sentence,
 * because the missing-grant case is genuinely the same fact everywhere: a
 * permission lives on a role, so the person to ask is whoever edits roles. Ten
 * hand-written versions of that were ten chances to leave the remedy off, and
 * five of them had.
 *
 * The REACH sentences are not all shared, and that is deliberate rather than
 * unfinished. [noReach] models reach over the PLACES work happens — a franchise
 * or a company. ContractPolicy, InvitationPolicy, OnboardingPolicy and
 * UserPolicy refuse over something else: the scope a person or a document is
 * attached to, where the useful sentence names the record rather than a place
 * on the map. Folding those into [noReach] would mean one function with a
 * parameter selecting between four unrelated sentences, which is a shared
 * spelling rather than a shared fact.
 *
 * ── Three reach failures, not one ────────────────────────────────
 *
 * [noReach] exists rather than one flat "outside your reach" because those two
 * words describe three different people.
 *
 *   1. Somebody assigned to Connecticut who asked about Georgia needs to hear
 *      the NAME of the place they missed.
 *   2. Somebody assigned nowhere at all needs to hear that they are assigned
 *      nowhere at all — telling them Georgia is outside their reach implies
 *      there is somewhere that is not, and they will spend the afternoon
 *      looking for it.
 *   3. Somebody assigned ABOVE the level being asked about, which holds nothing
 *      yet, is assigned perfectly well and has nowhere to act all the same.
 *      Telling them they are "not assigned to any franchise" is false, and
 *      sending them to an administrator for an assignment is a remedy that
 *      cannot work: there is no franchise to be assigned to. What is missing is
 *      the franchise, and standing above it is exactly what lets them add one.
 *
 * The third is currently OFF-INVARIANT rather than routine. §3 forbids a
 * company without a franchise and every edge enforces it — CompaniesController
 * requires a first place in the same transaction, FranchisesController refuses
 * to deactivate a company's last one and forbids moving a franchise between
 * companies, and there is no delete route. So a company with no places is not
 * something the API will hand you. It is one seeder, import or console command
 * away from existing, though, and the cost of covering it is one branch and one
 * sentence — against a refusal that would otherwise lie about the state and
 * name a fix that does nothing.
 *
 * ── The franchise is not called "franchise" everywhere ───────────
 *
 * §8 lets an organization rename it — "Territory", "Branch", "Shop" — and the
 * label lives on the organization. So a refusal that mentions the level asks the
 * caller's own organization what it is called rather than saying the word this
 * file happens to have been written with. That is the same rule the client
 * follows with `franchise_label`, and a refusal is text on a screen like any
 * other.
 *
 * ── What is deliberately NOT here ────────────────────────────────
 *
 * No authorization. [noReach] is only ever reached once a policy has already
 * decided the answer is no; what it asks the User afterwards is "do you stand
 * anywhere", which chooses a sentence and never a verdict. A policy that called
 * in here to work out WHETHER somebody may act would be a second home for §4,
 * which is the trap R6 names.
 */
final class Refusal
{
    /** Reach over the places work happens. */
    public const AT_FRANCHISE = 'franchise';

    /** Reach over a company — what adding or moving one is asked against. */
    public const AT_COMPANY = 'company';

    /**
     * The whole tenant — for [labelFor] only, never for [noReach].
     *
     * A placement can be organization-wide (see Onboarding::scope), so a
     * refusal sometimes has to NAME that level. Nothing stands above it, so
     * there is no reach failure to describe and [noReach] would have nothing to
     * choose between; [abovePosition] is what refuses at this level.
     *
     * Its value is Assignment::SCOPE_ORGANIZATION's, as the other two are the
     * matching scope types — that is what lets a caller hand a scope type
     * straight to [labelFor].
     */
    public const AT_ORGANIZATION = 'organization';

    /**
     * The role does not hold the permission at all.
     *
     * @param  string  $act  the thing they tried to do, as an infinitive without
     *                       "to": "add a job", "change this customer".
     */
    public static function permission(string $act): Response
    {
        return Response::deny(
            "You do not have permission to {$act}. That is part of a role rather "
            .'than of an account, so somebody who can edit roles can add it to yours.'
        );
    }

    /**
     * The permission is held; the place is not theirs to act on (§4).
     *
     * @param  string       $level  one of the AT_ constants — which kind of reach
     *                              was being asked for, and therefore which kind
     *                              of assignment is missing.
     * @param  string       $act    "add a job", "change this customer".
     * @param  string       $where  the place, named — "Connecticut", "Everdoors".
     * @param  string|null  $note   one more sentence, where the rule itself is
     *                              surprising. A separate argument rather than
     *                              something folded into [$act], because a clause
     *                              spliced into the middle of the sentence below
     *                              stops being a sentence.
     */
    public static function noReach(
        User $user,
        string $level,
        string $act,
        string $where,
        ?string $note = null,
    ): Response {
        $standsSomewhere = $level === self::AT_COMPANY
            ? $user->reachableCompanies()->isNotEmpty()
            : $user->reachableFranchises()->isNotEmpty();

        return $standsSomewhere
            ? self::outsideReach($act, $where, $note)
            : self::nowhereToAct($user, $level, $act);
    }

    /**
     * The act reaches the whole organization, and they stand below it (§4).
     *
     * Distinct from [noReach] because there is no "somewhere else you could do
     * this instead": adding a top-level company, or anything else whose effect is
     * the whole business, is refused by POSITION rather than by place. The
     * owner's rule, recorded as decision 12 in EZBIZI-TODO.md: authority never
     * flows upward.
     */
    public static function abovePosition(string $act): Response
    {
        return Response::deny(
            "Only somebody assigned at the whole organization can {$act} — it "
            .'affects the entire business rather than one part of it, and authority '
            .'does not flow upwards from a company or a single place.'
        );
    }

    /** They stand somewhere; that place is not it. */
    private static function outsideReach(string $act, string $where, ?string $note): Response
    {
        return Response::deny(trim(sprintf(
            'You hold the permission to %s, but %s is outside where you are assigned, '
            .'so it is not yours to act on. Somebody assigned there, or above it, can — '
            .'and an administrator can widen your assignment. %s',
            $act,
            $where,
            $note ?? '',
        )));
    }

    /**
     * No place at this level would have worked — for one of two reasons.
     *
     * The state that reads most like a broken app: a control on screen, dead,
     * for somebody whose role is exactly right. What is missing is never the
     * permission, and §4 keeps assignment and permission apart on purpose.
     *
     * Which of the two it is decides the remedy, so it is decided here rather
     * than left to the caller: every caller would have to ask the same question
     * of the same User to answer it, which is the copy this class exists to
     * avoid.
     *
     * Public as well as being [noReach]'s other half, because the question is
     * sometimes asked with no place named at all — "may you open this form",
     * before anything has been picked — and then there is nothing to name.
     */
    public static function nowhereToAct(User $user, string $level, string $act): Response
    {
        $label = self::labelFor($user, $level);

        return self::standsAbove($user, $level)
            ? self::nothingBelow($act, $label)
            : self::unassigned($act, $label);
    }

    /**
     * Does this person's authority sit ABOVE the level they were asked about?
     *
     * Only ever asked once reach at [$level] has already come back empty, so a
     * yes means "assigned higher up, and there is nothing down there yet"
     * rather than "assigned somewhere else at the same level" — that one is
     * [outsideReach], and it was decided before this was called.
     *
     * Authority runs downwards only (§4), so the organization is above
     * everything and a company is above the places in it. Nothing is above the
     * organization, which is why an organization-level assignment answers true
     * whatever the level.
     */
    private static function standsAbove(User $user, string $level): bool
    {
        $organization = $user->organization;

        if ($organization !== null && $user->canActOnOrganization($organization)) {
            return true;
        }

        return $level === self::AT_FRANCHISE && $user->reachableCompanies()->isNotEmpty();
    }

    /** They stand nowhere, so the fix is an assignment. */
    private static function unassigned(string $act, string $label): Response
    {
        return Response::deny(
            "You have permission to {$act}, but you are not assigned to any {$label}, "
            ."so there is nowhere to do it. An administrator can assign you to a {$label}."
        );
    }

    /**
     * They stand above it, and there is nothing down there yet.
     *
     * The sentence has to correct two things the assignment one would get
     * wrong. They ARE assigned, so saying otherwise is false. And an
     * administrator cannot assign them to a place that does not exist, so
     * naming that remedy sends them to somebody who will not be able to help.
     *
     * "Nothing you are assigned to has a {$label} in it" rather than "there are
     * no {$label}s": another part of the business may well have plenty, and
     * they are simply not this person's.
     */
    private static function nothingBelow(string $act, string $label): Response
    {
        return Response::deny(
            "You have permission to {$act}, but nothing you are assigned to has a {$label} "
            ."in it yet, so there is nowhere to do it. What is missing is a {$label} rather "
            .'than an assignment: add one where you are already assigned, or ask an '
            .'administrator to.'
        );
    }

    /**
     * What this organization calls the level being refused over (§8).
     *
     * Public, because the level turns up in refusals this class does not write.
     * OnboardingPolicy names the scope a new starter is being placed at —
     * "Somebody with authority over that franchise can" — and was splicing
     * Assignment's own scope type into the sentence, which says "franchise" to a
     * business that calls them Territories. §8 is why this method exists, so the
     * answer belongs here rather than being a rule each policy remembers.
     *
     * @param  string  $level  an AT_ constant, or any of Assignment's three
     *                         scope types — they are the same three strings.
     */
    public static function labelFor(User $user, string $level): string
    {
        return strtolower(match ($level) {
            self::AT_ORGANIZATION => 'organization',
            self::AT_COMPANY      => 'company',

            // The only one an organization can rename today. The others are
            // spelled out above rather than defaulted through, so adding a
            // `company_label` is a change here and nowhere else.
            default => $user->organization?->franchise_label ?? 'franchise',
        });
    }
}
