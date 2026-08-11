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
 * hand them in ten seconds. ContractPolicy, InvitationPolicy, RolePolicy,
 * UserPolicy and OnboardingPolicy already answer with a sentence; JobPolicy,
 * CustomerPolicy, AutomationPolicy, CompanyPolicy and FranchisePolicy did not.
 *
 * There are only ever THREE things gone wrong, and between them those five
 * policies hit all three:
 *
 *   - the role does not hold the permission                  → [permission]
 *   - it holds it, but not over that place (§4 reach)        → [noReach]
 *   - the act is the whole organization's to make            → [abovePosition]
 *
 * Written five times over those become five slightly different sentences, and
 * the reach one in particular is the one people get wrong: "you do not have
 * permission" is the WRONG thing to tell somebody who has the permission and no
 * assignment, because the fix is an assignment and they will go and ask for a
 * permission they already hold.
 *
 * ── Two reach failures, not one ──────────────────────────────────
 *
 * [noReach] exists rather than one flat "outside your reach" because those two
 * words describe two different people. Somebody assigned to Connecticut who
 * asked about Georgia needs to hear the NAME of the place they missed. Somebody
 * assigned nowhere at all needs to hear that they are assigned nowhere at all —
 * telling them Georgia is outside their reach implies there is somewhere that
 * is not, and they will spend the afternoon looking for it. The second is the
 * likelier of the two in a young organization, where roles exist before
 * assignments do.
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
     * They stand nowhere, so no place would have worked.
     *
     * The state that reads most like a broken app: a control on screen, dead,
     * for somebody whose role is exactly right. What is missing is an assignment,
     * and §4 keeps assignment and permission apart on purpose.
     *
     * Public as well as being [noReach]'s other half, because the question is
     * sometimes asked with no place named at all — "may you open this form",
     * before anything has been picked — and then there is nothing to name.
     */
    public static function nowhereToAct(User $user, string $level, string $act): Response
    {
        $label = strtolower(
            $level === self::AT_COMPANY
                ? 'company'
                : ($user->organization?->franchise_label ?? 'franchise')
        );

        return Response::deny(
            "You have permission to {$act}, but you are not assigned to any {$label}, "
            ."so there is nowhere to do it. An administrator can assign you to a {$label}."
        );
    }
}
