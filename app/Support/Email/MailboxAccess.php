<?php

namespace App\Support\Email;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Mailbox;
use App\Models\MailboxGrant;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;

/**
 * May this person open that inbox? Asked in one place (§4).
 *
 * ═══════════════════════════════════════════════════════════════════
 *  THREE QUESTIONS, AND ALL THREE ARE ASKED.
 *
 *   1. PERMISSION — does their role hold `email.view` at all? This is
 *      the module's tab permission, the thing §4 says every module is
 *      reached through, and it is what makes the whole module absent
 *      for a role that was never given it.
 *
 *   2. GRANT — has the organization given their role THIS inbox?
 *      {@see MailboxGrant}, a tenant record. The owner's requirement:
 *      *"the organization can grant access to the email module to a
 *      role, but not necessary to all the emails connected to the
 *      module."*
 *
 *   3. REACH — does their assignment cover the level the inbox hangs
 *      off? §4's authority rule, unchanged and asked of the User's
 *      own methods.
 *
 *  ── GRANT AND REACH ARE DIFFERENT QUESTIONS ─────────────
 *
 *  They are easy to conflate and the difference decides real cases.
 *
 *  A grant is about TRUST IN A JOB: "people doing the sales job are
 *  trusted with the sales inbox." It is attached to a role, it is the
 *  same for everybody holding that role, and it says nothing about
 *  where anybody works.
 *
 *  Reach is about WHERE SOMEBODY STANDS: "this person is assigned to
 *  the Connecticut branch, so company-level things are not theirs."
 *  It is attached to a person's assignment and says nothing about
 *  what they are trusted with.
 *
 *  Consider the case that makes it concrete. A-1 Chimney holds
 *  info@a-1chimney.com at COMPANY level. The "Branch Manager" role is
 *  granted it — a perfectly reasonable thing for an organization to
 *  do, since branch managers answer customer mail. Jenny is a Branch
 *  Manager assigned to Connecticut only.
 *
 *    - Jenny has the GRANT: her role was given that inbox.
 *    - Jenny does NOT have the REACH: her assignment is one franchise,
 *      and the inbox belongs to the company above her.
 *
 *  She may not open it, and the reason is not that the organization
 *  distrusts branch managers with customer mail — it is that this
 *  particular mailbox is above where she stands. Checking only the
 *  grant would hand a franchise user a company-level inbox, which is
 *  the exact failure the house rules name. Checking only reach would
 *  give every branch manager every inbox at or below their level,
 *  which is the exact thing the owner asked to be able to withhold.
 *
 *  The refusals below say WHICH of the two is missing, because the
 *  remedies are different people: a missing grant is fixed in the
 *  roles editor, a missing reach is fixed by an assignment.
 *
 *  ── THE SWITCHER IS A LENS, NOT A FOURTH GRANT ─────────────
 *
 *  Permission, grant and reach decide whether this person may ever
 *  open the inbox. The company switcher decides whether it is on
 *  screen right now. development@ezbizi.com belongs to EzBizi;
 *  looking at A-1 Chimney must not list it — on Settings or Inbox —
 *  and must not open it by id either. The organization view lists
 *  every company's. A company view lists that company, its Sub
 *  Companies, and the franchises under them. A franchise view lists
 *  that branch's. Same rule the Workspace connections already follow.
 * ═══════════════════════════════════════════════════════════════════
 *
 * ── Managing a mailbox is a different act from reading it ────────
 *
 * {@see mayManage} asks for `email.edit` and REACH, and deliberately not for a
 * grant. Somebody has to be able to connect info@ before anybody can be given
 * it, and requiring a grant to do so is a dead end on the first day: the
 * administrator would have to grant themselves access to an inbox that does not
 * exist yet.
 *
 * It also matches how businesses actually work — whoever sets a mailbox up is
 * usually not whoever answers it — and it is a smaller authority than reading:
 * connecting, renaming and disconnecting an address are all visible acts that
 * change what other people can see, while reading is invisible and is somebody
 * else's correspondence.
 *
 * ── The Administrator holds every inbox, and holds no rows ───────
 *
 * `Role::hasPermission` answers true for every key when the role is the
 * organization's own Administrator, and question 2 works the same way: the
 * Administrator holds every inbox this organization has connected, without a
 * `mailbox_grants` row for any of them. Nothing writes rows for it, so reading
 * its rows would report an administrator who can open no mail at all.
 *
 * ── That was decided the other way first, and this is why it changed ──
 *
 * The first draft here refused an administrator who had not granted their own
 * role, on the argument that "the boss silently reads everything" should be a
 * decision the organization RECORDED rather than a default that came with the
 * software — and it is a good argument.
 *
 * What settled it was not the argument but a CONTRADICTION. The roles editor is
 * built on {@see \App\Support\Access\InboxAccess::heldBy}, which expands the
 * Administrator to every inbox exactly as it expands its permissions, and that
 * is what the roles screen draws. So the two halves of one feature would have
 * disagreed: the screen would show the Administrator holding info@, and opening
 * it would be refused with a sentence telling them to go and grant what the
 * screen says they already have. A product that says one thing and does another
 * is worse than either policy, and the policy that matches how every other
 * permission in this application behaves is the one that wins.
 *
 * The owner's requirement is untouched by this. It is about withholding inboxes
 * from ROLES the organization creates — "not necessary to all the emails
 * connected to the module" — and every one of those is still refused without a
 * grant. The Administrator is the role that can hand out the grants in the first
 * place; withholding from it withholds nothing.
 */
final class MailboxAccess
{
    /** The module's tab permission — reaching the inbox at all. */
    public const READ = 'email.view';

    /** Connecting, renaming and disconnecting an address. */
    public const MANAGE = 'email.edit';

    /**
     * Every mailbox this person may READ, grant and reach both satisfied.
     *
     * @return Collection<int, Mailbox>
     */
    public function readable(User $user): Collection
    {
        if (! $user->hasPermission(self::READ)) {
            return collect();
        }

        return $this->granted($user)->filter(fn (Mailbox $m) => $this->reaches($user, $m))->values();
    }

    /**
     * Every mailbox this person may MANAGE — reach only, no grant.
     *
     * @return Collection<int, Mailbox>
     */
    public function manageable(User $user): Collection
    {
        if (! $user->hasPermission(self::MANAGE)) {
            return collect();
        }

        return Mailbox::query()->get()->filter(fn (Mailbox $m) => $this->reaches($user, $m))->values();
    }

    public function mayRead(User $user, Mailbox $mailbox): bool
    {
        return $this->readRefusal($user, $mailbox, 'open this inbox') === null;
    }

    public function mayManage(User $user, Mailbox $mailbox): bool
    {
        return $this->manageRefusal($user, $mailbox, 'change this inbox') === null;
    }

    /**
     * Why this person may not read that inbox, as a sentence — or null.
     *
     * R8 all the way through: never a permission key, never a class name, never
     * "grant" as a noun the reader has not met. Each branch names the ACT, the
     * OBSTACLE and WHO CAN FIX IT.
     *
     * @param  string  $act  an infinitive without "to": "open this inbox",
     *                       "reply from this inbox"
     */
    public function readRefusal(User $user, Mailbox $mailbox, string $act): ?string
    {
        if (! $user->hasPermission(self::READ)) {
            return Refusal::permission($act)->message();
        }

        /*
         * REACH BEFORE VIEW (2026-10-16): the view says "switch there", which
         * is no answer to somebody who can never stand there.
         */
        if (! $this->reaches($user, $mailbox)) {
            return $this->outOfReach($user, $mailbox, $act);
        }

        if (! $this->inView($user, $mailbox)) {
            return $this->outOfView($user, $mailbox, $act);
        }

        /*
         * REACH BEFORE GRANT, and the order is the point.
         *
         * Reach failing means the inbox is above where this person stands, and
         * telling them instead that their role has not been given access sends
         * them to an administrator who would add the grant, change nothing, and
         * both of them would be baffled. The narrower, structural fact comes
         * first.
         */
        if (! $this->isGranted($user, $mailbox)) {
            return sprintf(
                'You cannot %s. %s is a shared inbox, and access to each one is given out '
                .'separately — it has not been given to your role, or to you. Somebody who can '
                .'edit roles can add it to your role, or somebody who manages Email can add you '
                .'to it in Email, Settings.',
                $act,
                $mailbox->address,
            );
        }

        return null;
    }

    /** The same question about managing the connection, where no grant is asked for. */
    public function manageRefusal(User $user, Mailbox $mailbox, string $act): ?string
    {
        if (! $user->hasPermission(self::MANAGE)) {
            return Refusal::permission($act)->message();
        }

        if (! $this->reaches($user, $mailbox)) {
            return $this->outOfReach($user, $mailbox, $act);
        }

        if (! $this->inView($user, $mailbox)) {
            return $this->outOfView($user, $mailbox, $act);
        }

        return null;
    }

    // ── the three questions, separately ──────────────────────────

    /**
     * Has the organization given this person's role this inbox?
     *
     * Read through the tenant-scoped {@see MailboxGrant} query rather than off a
     * relation on the user, so the organization filter applies: a role id from
     * another tenant simply matches nothing.
     *
     * The Administrator answers true without a row, matching
     * {@see \App\Support\Access\InboxAccess::heldBy} — which is what the roles
     * editor draws — and matching `Role::hasPermission`, which answers true for
     * every key for the same role. See the class note for why the two have to
     * agree.
     */
    public function isGranted(User $user, Mailbox $mailbox): bool
    {
        if ($user->isAdministrator()) {
            return true;
        }

        return $this->grantsOf($user)
            ->where('mailbox_id', $mailbox->getKey())
            ->exists();
    }

    /**
     * Every grant that applies to this person: their ROLE's, or THEIR OWN.
     *
     * Jose, 2026-10-06: "assign emails to specific people, instead of just
     * roles". A person grant answers the same question a role grant does --
     * may this account open this inbox -- so it is read in the same place,
     * and the two checks here (isGranted, granted) both go through this one
     * query and cannot drift apart.
     *
     * It is only the GRANT half. The Email permission still comes from the
     * person's role, and reach still applies (readRefusal checks both first),
     * so giving somebody an inbox never lets them past either of those.
     *
     * A person with no role still matches their own grants here; they are
     * stopped by the permission check, not by this query pretending they hold
     * nothing.
     */
    private function grantsOf(User $user): \Illuminate\Database\Eloquent\Builder
    {
        return MailboxGrant::query()->where(function ($query) use ($user) {
            $query->where('user_id', $user->getKey());

            if ($user->role_id !== null) {
                $query->orWhere('role_id', $user->role_id);
            }
        });
    }

    /**
     * Is this inbox on screen for the company (or organization) they are looking at?
     *
     * Organization view: every inbox they already have grant and reach for.
     * Company view: that company, its Sub Companies, and franchises under them
     * — not a sibling, and not an organization-level inbox (those live on the
     * group view, the same way a Workspace grant does). Franchise view: that
     * branch only.
     */
    public function inView(User $user, Mailbox $mailbox): bool
    {
        $view = ViewScope::forUser($user);

        if ($view->isOrganization()) {
            return true;
        }

        if ($view->type === ViewScope::COMPANY) {
            return match ($mailbox->scope_type) {
                Mailbox::SCOPE_COMPANY => in_array($mailbox->scope_id, $view->companyIds(), true),
                Mailbox::SCOPE_FRANCHISE => in_array($mailbox->scope_id, $view->franchiseIds(), true),
                default => false,
            };
        }

        return $mailbox->scope_type === Mailbox::SCOPE_FRANCHISE
            && $mailbox->scope_id === $view->id;
    }

    /**
     * Does this person's authority cover the level the inbox hangs off? (§4)
     *
     * The three branches delegate to the User's own methods rather than
     * re-deriving anything. §4's rule has one home and this is not it — a second
     * copy here is the trap R6 names, and the settings screen's history records
     * what happens when one copy walks the tree the wrong way round.
     */
    public function reaches(User $user, Mailbox $mailbox): bool
    {
        return match ($mailbox->scope_type) {
            Mailbox::SCOPE_ORGANIZATION => $user->organization !== null
                && $mailbox->scope_id === $user->organization_id
                && $user->canActOnOrganization($user->organization),

            Mailbox::SCOPE_COMPANY => ($company = Company::find($mailbox->scope_id)) !== null
                && $user->canActOnCompany($company),

            Mailbox::SCOPE_FRANCHISE => ($franchise = Franchise::find($mailbox->scope_id)) !== null
                && $user->canActOnFranchise($franchise),

            // A scope_type this build does not recognise. Refused rather than
            // defaulted: an unknown level is not a level everybody reaches.
            default => false,
        };
    }

    /**
     * The mailboxes this person's role holds a grant for, whatever their reach.
     *
     * @return Collection<int, Mailbox>
     */
    private function granted(User $user): Collection
    {
        // The Administrator holds every inbox and owns no rows — see isGranted.
        if ($user->isAdministrator()) {
            return Mailbox::query()->orderBy('address')->get();
        }

        return Mailbox::query()
            ->whereIn('id', $this->grantsOf($user)->select('mailbox_id'))
            ->orderBy('address')
            ->get();
    }

    /**
     * The switcher refusal: they may well have the grant, they are just
     * looking at a different company.
     */
    private function outOfView(User $user, Mailbox $mailbox, string $act): string
    {
        $here = ViewScope::forUser($user)->label();

        $there = match ($mailbox->scope_type) {
            Mailbox::SCOPE_COMPANY => Company::find($mailbox->scope_id)?->name,
            Mailbox::SCOPE_FRANCHISE => Franchise::find($mailbox->scope_id)?->name,
            default => $user->organization?->name,
        } ?? 'another part of the business';

        return sprintf(
            'You cannot %s while looking at %s. %s belongs to %s. Switch there, or to the organization, to see it.',
            $act,
            $here,
            $mailbox->address,
            $there,
        );
    }

    /**
     * The reach refusal, naming the level and what this organization calls it.
     *
     * §8 lets an organization rename a franchise — "Territory", "Branch" — so
     * the sentence asks {@see Refusal} for the label rather than saying the word
     * this file happens to be written with.
     */
    private function outOfReach(User $user, Mailbox $mailbox, string $act): string
    {
        if ($mailbox->scope_type === Mailbox::SCOPE_ORGANIZATION) {
            return Refusal::abovePosition($act)->message();
        }

        $level = $mailbox->scope_type === Mailbox::SCOPE_COMPANY
            ? Refusal::AT_COMPANY
            : Refusal::AT_FRANCHISE;

        return Refusal::noReach(
            $user,
            $level,
            $act,
            // Safe to name: the caller reached this mailbox by id, and the id
            // came from a list this application already showed them.
            $mailbox->address,
            sprintf(
                'This inbox belongs to a %s above where you are assigned, so being given '
                .'access to it in your role would not be enough on its own.',
                Refusal::labelFor($user, $mailbox->scope_type),
            ),
        )->message();
    }
}
