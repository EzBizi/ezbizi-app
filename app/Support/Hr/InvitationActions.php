<?php

namespace App\Support\Hr;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Models\Invitation;
use App\Models\Onboarding;
use App\Models\User;
use App\Services\Hr\Invitations;
use App\Support\Access\AdministratorFloor;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Contracts\FieldDefinition;
use App\Support\Contracts\FieldType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Resending, withdrawing and starting paperwork — the one implementation.
 *
 * Extracted from HrInvitationsController and HrOnboardingsController on
 * 2026-09-16, because Automations needed to cause these three from a rule and
 * declined to copy the controllers into ActionRunner. They were right to
 * decline: what "resend" means here is not a column write. It mints a new
 * credential, kills the previous one, clears `opened_at` because "received" is
 * a claim about a link that no longer exists, and may move the address — and a
 * second copy of that would have drifted the first time one half changed,
 * silently, because nothing would have failed.
 *
 * ── The split, and it is the half a caller gets wrong ────────────
 *
 * TWO kinds of rule guard these acts and only one kind is in here.
 *
 * The ACTOR rules ask whether this person may act — the policy, the
 * escalation bound in `PermissionCatalogue::assertRoleAssignable`, the §4
 * reach check. They have no answer when there is no person, which is exactly
 * the case a rule runs in, so they stay with the caller. HrInvitationsController
 * still makes every one of them.
 *
 * The STATE rules ask whether the invitation is in a fit state to be acted on
 * at all — already accepted, already withdrawn, already carrying paperwork.
 * Those are true regardless of who is asking, and they are in here with the
 * side effects they guard.
 *
 * So: the caller decides WHETHER it may act, this decides what acting MEANS.
 * The same division Fleet wrote into `VehicleAssignments`. A rule calling
 * these is acting as the organization rather than as a person, and no reach or
 * role check runs — a deliberate property, not an oversight, and stated here
 * because a caller who assumed otherwise would be safe by accident.
 */
class InvitationActions
{
    public function __construct(
        private readonly Invitations $invitations,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /**
     * A fresh link, optionally to a corrected address. Returns the accept URL.
     *
     * The address rides this act rather than being a field edit of its own,
     * and that is a security boundary rather than a modelling preference: the
     * row and the credential are different things, so repointing the row would
     * change where the NEXT message goes and could not recall the one already
     * delivered. A mistyped address belonging to a real person would leave
     * that stranger holding a live invitation into this organization while the
     * screen said it was fixed. Reissuing kills the old token, so correcting
     * the address is safe only as part of this.
     *
     * The caller sends the mail. This returns rather than delivering, because
     * who the message comes from and what the sender is told about its fate
     * belong to the surface that asked — a screen hands the link over when a
     * send fails, and a rule has nobody to hand it to.
     *
     * ── WHAT COMES BACK IS A LIVE CREDENTIAL ─────────────────────
     *
     * The RAW TOKEN, which exists nowhere else: only its hash is stored, and
     * this is the one moment it can be read. Anyone holding it can accept the
     * invitation and become that person. So it must not be logged, must not
     * reach an audit row, and must not be written into an automation run log —
     * turn it into a URL with {@see Invitations::acceptUrl} and send it, or
     * discard it. A rule has no use for it at all.
     *
     * Returned rather than kept back because the screen genuinely needs it:
     * the panel shows the link so somebody can hand it over when the mail does
     * not arrive, which is the whole reason a failed send is survivable.
     */
    public function resend(Invitation $invitation, ?string $email = null): string
    {
        $this->assertOpen($invitation);

        if ($invitation->isHeld()) {
            throw new CannotActOnInvitation(
                'This invitation goes out when the employer signs the paperwork, so there is '
                .'nothing to resend yet.'
            );
        }

        $moving = $email !== null && strtolower(trim($email)) !== $invitation->email;

        return DB::transaction(function () use ($invitation, $email, $moving) {
            $was = $invitation->email;

            if ($moving) {
                $invitation->forceFill(['email' => strtolower(trim($email))])->save();
            }

            $token = $this->invitations->resend($invitation);

            // A distinct action for a distinct fact: somebody's invitation went
            // to the wrong place and was corrected. Both addresses, because
            // "which one did we get wrong" is what this row will be read to
            // answer. Never the token — an audit log is read by more people
            // than a credential should be.
            $this->audit->log(
                $moving ? 'invitation.readdressed' : 'invitation.resent',
                $invitation,
                metadata: array_filter([
                    'email' => $invitation->email,
                    'was' => $moving ? $was : null,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ], fn ($v) => $v !== null),
            );

            return $token;
        });
    }

    /**
     * Withdraw it. Idempotent by design.
     *
     * An invitation already withdrawn is not an error to withdraw again — the
     * caller wanted it gone and it is gone. That matters more for a rule than
     * for a screen: "withdraw anything unaccepted after thirty days" would
     * otherwise fail noisily every day after the first, filling a run log with
     * refusals that describe success.
     */
    /**
     * A person may withdraw one already accepted (Jose, 2026-10-05: "she
     * should be able to still revoke it. regardless of anything"): its
     * paperwork is cancelled whatever its state and the account it opened is
     * switched off, which Records can undo. A rule ($by null) still may not,
     * so no rule switches somebody off as a side effect of tidying.
     */
    public function revoke(Invitation $invitation, ?User $by = null): void
    {
        if ($invitation->accepted_at !== null && $by === null) {
            throw new CannotActOnInvitation(
                'That invitation has already been accepted, so there is nothing left to '
                .'withdraw. Switch the person’s access off instead if that is what you mean.'
            );
        }

        if ($invitation->revoked_at !== null) {
            return;
        }

        $account = $invitation->accepted_at !== null ? $invitation->acceptedUser : null;
        if ($account !== null && $account->is($by)) {
            throw new CannotActOnInvitation('That invitation opened your own account, so withdrawing '
                .'it would switch you off. Ask a colleague to do it if you mean it.');
        }

        // Read before the write, so the event can say what it was withdrawn
        // FROM — one somebody had opened and one nobody ever looked at are
        // different things to withdraw, and `changed_from` is how a rule tells
        // them apart.
        $before = $invitation->status();

        DB::transaction(function () use ($invitation, $before, $account, $by) {
            // Inside the transaction: the floor locks the administrator rows
            // until the switch-off below lands.
            if ($account !== null && $account->is_active) {
                try {
                    app(AdministratorFloor::class)->assertNotTheLast($account, 'is_active');
                } catch (ValidationException $refusal) {
                    throw new CannotActOnInvitation((string) collect($refusal->errors())->flatten()->first());
                }
            }

            $invitation->forceFill(['revoked_at' => now()])->save();

            Onboarding::query()->where('invitation_id', $invitation->getKey())
                ->where('status', '!=', Onboarding::CANCELLED)->get()
                ->each(fn (Onboarding $onboarding) => $this->cancelOnboarding($onboarding, $by));

            $switchedOff = $account !== null && $account->is_active;
            if ($switchedOff) {
                $account->forceFill(['is_active' => false])->save();
            }

            $this->audit->log('invitation.revoked', $invitation, metadata: [
                'email' => $invitation->email,
                'account_switched_off' => $switchedOff,
            ]);

            // Inside the transaction, like every emit: the outbox row and the
            // withdrawal commit together or neither does.
            $this->events->emit('invitation.revoked', $invitation, [
                'before' => ['status' => $before],
                'after' => [
                    'status' => $invitation->status(),
                    'email' => $invitation->email,
                    'role' => $invitation->role?->name,
                    'scope_type' => $invitation->scopeType(),
                    'scope_id' => $invitation->scopeId(),
                    'revoked_at' => $invitation->revoked_at->toIso8601String(),
                ],
            ]);
        });
    }

    /**
     * Send an invitation held for the employer's signature: a fresh link and
     * a fresh week, emailed now (Jose, 2026-10-06). Nothing when it is not
     * held, or was accepted or withdrawn meanwhile.
     */
    public function releaseHeld(Invitation $invitation): void
    {
        if (! $invitation->isHeld() || $invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            return;
        }

        $token = DB::transaction(function () use ($invitation) {
            $invitation->forceFill(['held_at' => null])->save();
            $token = $this->invitations->resend($invitation);
            $this->audit->log('invitation.released', $invitation, metadata: [
                'email' => $invitation->email,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ]);

            return $token;
        });

        try {
            \Illuminate\Support\Facades\Notification::route('mail', $invitation->email)
                ->notify(new \App\Notifications\InvitationLink($invitation, $this->invitations->acceptUrl($token)));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Stop an onboarding, whatever its state (Jose, 2026-10-05: "regardless
     * of anything"). The row, its answers, documents and signatures stay:
     * `cancelled` records that the organization changed its mind.
     */
    public function cancelOnboarding(Onboarding $onboarding, ?User $actor): void
    {
        $before = $onboarding->status;

        DB::transaction(function () use ($onboarding, $before, $actor) {
            $onboarding->forceFill([
                'status'       => Onboarding::CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $this->audit->log('onboarding.cancelled', $onboarding, metadata: [
                'from'     => $before,
                'contract' => $onboarding->contractVersion?->contract?->name,
            ]);

            $this->events->emit('onboarding.cancelled', $onboarding, [
                'before' => ['status' => $before],
                'after'  => [
                    'status'       => $onboarding->status,
                    'cancelled_at' => $onboarding->cancelled_at?->toIso8601String(),
                    'contract'     => $onboarding->contractVersion?->contract?->name,
                ],
            ], actor: $actor);
        });
    }

    /**
     * Open the paperwork for somebody who has been invited.
     *
     * `$startedBy` is null when a rule causes this, and the column is nullable
     * for exactly that reason — "who started it" has no answer when nobody
     * did. The event carries the same null rather than borrowing whoever
     * happened to trigger the rule, because attributing an act to somebody who
     * did not perform it is worse than recording that it was automatic.
     *
     * Four state rules, all in here: the invitation must not already carry
     * paperwork, must not be withdrawn, must not have lapsed unaccepted, and
     * the contract must have a published version WITH a signature field. That
     * last one is refused here rather than discovered at the end, because the
     * end is after somebody has filled in twenty fields — `contract_signatures`
     * has to name a real signature field, so an onboarding against a version
     * without one could be completed by nobody, ever, with nothing on screen
     * saying why.
     */
    public function startOnboarding(
        Invitation $invitation,
        Contract $contract,
        ?User $startedBy = null,
        ?string $companyId = null,
    ): Onboarding {
        $this->assertCanCarryOnboarding($invitation);

        $contract->loadMissing('currentVersion');
        $version = $contract->currentVersion;

        if ($version === null) {
            throw new CannotActOnInvitation(
                'That template has never been published, so there is nothing to ask. Publish '
                .'a version of it first.',
                field: 'contract_id',
            );
        }

        if (! $this->usable($version)) {
            throw new CannotActOnInvitation(sprintf(
                'Version %d of "%s" has no signature field, so there would be no way to agree '
                .'to it. Add one to the template and publish, then start the onboarding — a '
                .'form that can be filled in and never finished is worse than one that refuses '
                .'to start.',
                $version->version, $contract->name,
            ), field: 'contract_id');
        }

        return DB::transaction(function () use ($invitation, $version, $startedBy) {
            // Re-asked under a lock, because the check above raced: two requests
            // both seeing no onboarding would both create one, and the
            // invitation would carry two contracts with nothing saying which is
            // real. The unlocked read stays for the sentence it produces.
            $live = Onboarding::query()
                ->where('invitation_id', $invitation->getKey())
                ->where('status', '!=', Onboarding::CANCELLED)
                ->lockForUpdate()
                ->first();

            if ($live !== null) {
                throw new CannotActOnInvitation(
                    'That invitation already has an onboarding in flight. Cancel it first '
                    .'if you meant a different contract.'
                );
            }

            $onboarding = Onboarding::create([
                'invitation_id' => $invitation->getKey(),
                // Set when the invitation has already been accepted. Null is the
                // ordinary case: the employer starts the paperwork and pre-fills
                // it before the person has an account.
                'user_id' => $invitation->accepted_user_id,
                'contract_version_id' => $version->getKey(),
                // The company it is under: the one chosen, else the one the
                // invitation places them in (a franchise's company), else the
                // organization.
                'company_id' => $companyId ?? $invitation->assigned_company_id
                    ?? ($invitation->assigned_franchise_id === null ? null
                        : \App\Models\Franchise::query()->whereKey($invitation->assigned_franchise_id)->value('company_id')),
                'status' => Onboarding::IN_PROGRESS,
                'started_at' => now(),
                'started_by_user_id' => $startedBy?->getKey(),
            ]);

            $this->audit->log('onboarding.started', $onboarding, metadata: [
                'invitation_id' => $invitation->getKey(),
                'contract' => $version->contract?->name,
                'version' => $version->version,
                'email' => $invitation->email,
            ]);

            /*
             * Inside the transaction, which is what an outbox is for: the form
             * and the fact that it opened commit together or neither does.
             *
             * The scope a rule matches on is not in this payload and does not
             * need to be — Onboarding::automationScope() reads it off the
             * invitation, so a rule scoped to one franchise sees this and a rule
             * scoped to another does not.
             */
            $this->events->emit('onboarding.started', $onboarding, ['after' => [
                'status' => $onboarding->status,
                'contract' => $version->contract?->name,
                'contract_id' => $version->contract_id,
                'version' => $version->version,
                'invitation_id' => $invitation->getKey(),
                'email' => $invitation->email,
            ]], actor: $startedBy);

            return $onboarding;
        });
    }

    /**
     * The contracts a rule may be pointed at — id and name, nothing else.
     *
     * Automations needs a picker: a rule that starts somebody's paperwork has
     * to name which template, and that cannot be derived. They serve it from
     * their own catalogue, on `automations.view`, the way they already serve
     * roles — so a rule author need not hold HR's permissions to build one.
     *
     * The LIST comes from here rather than from a query of theirs, and that is
     * the whole point: it offers exactly what {@see startOnboarding} would
     * accept, because both ask {@see usable}. A picker built from its own
     * `where` would eventually offer a template the act refuses, and the
     * author would be told at run time — in a log, days later — that the
     * choice the builder gave them was never valid.
     *
     * NAME ONLY, and deliberately no version. A rule names the CONTRACT and
     * the version is resolved when it fires, so "Technician v3" in a picker
     * would read as a pin that does not exist: publish v4 and the rule quietly
     * uses it, which is right, and the label would have said otherwise.
     *
     * Nothing about the content. A contract's fields are what somebody's
     * salary and home address live in; the title is the most that should cross
     * into a module whose permission is about writing rules.
     */
    public function offerableContracts(): array
    {
        return Contract::query()
            // Nothing writes this column today — the migration that added it
            // says new onboardings should not offer a contract that was a
            // mistaken split of another, and no code has ever honoured that.
            // Filtering anyway costs nothing and means the picker is already
            // right on the day somebody implements archiving.
            ->whereNull('archived_at')
            ->with('currentVersion')
            ->orderBy('name')
            ->get()
            ->filter(fn (Contract $c) => $this->usable($c->currentVersion))
            ->map(fn (Contract $c) => ['id' => $c->getKey(), 'label' => $c->name])
            ->values()
            ->all();
    }

    /**
     * Could paperwork actually be finished against this version?
     *
     * A contract nobody can sign is not a contract: `contract_signatures`
     * has to name a real signature field, so an onboarding against a version
     * without one could be completed by nobody, ever, with nothing on screen
     * saying why. Asked by the act and by the picker, so the picker cannot
     * offer what the act refuses.
     */
    private function usable(?ContractVersion $version): bool
    {
        return $version !== null
            && collect($version->liveFields())
                ->contains(fn (FieldDefinition $f) => $f->type === FieldType::SIGNATURE);
    }

    /**
     * Can this invitation carry paperwork at all?
     *
     * Four different sentences rather than one, because they send somebody to
     * four different places: cancel the one in flight, look at the agreement
     * that already exists, send a fresh invitation, or resend this one.
     */
    private function assertCanCarryOnboarding(Invitation $invitation): void
    {
        if ($invitation->onboarding !== null) {
            throw new CannotActOnInvitation(
                $invitation->onboarding->status === Onboarding::COMPLETED
                    ? 'That invitation already produced a signed agreement. A second '
                        .'onboarding against it would be two contracts for one arrival, with '
                        .'nothing saying which is real.'
                    : 'That invitation already has an onboarding in flight. Cancel it first '
                        .'if you meant a different contract.'
            );
        }

        if ($invitation->revoked_at !== null) {
            throw new CannotActOnInvitation(
                'That invitation was withdrawn, so nobody is coming. Send a new one if that '
                .'has changed.'
            );
        }

        // An expired link is one nobody can accept, so the form would have no
        // second side. Resending restarts the week, which is what that button is
        // for and is a smaller act than starting paperwork.
        if ($invitation->accepted_at === null && $invitation->hasExpired()) {
            throw new CannotActOnInvitation(
                'That invitation has lapsed and its link no longer works, so the person could '
                .'never fill in their half. Resend it first.'
            );
        }
    }

    /**
     * Is this invitation in a state where a fresh link means anything?
     *
     * Accepted and withdrawn are separate sentences on purpose: one is "they
     * are already here", the other is "somebody decided against this", and a
     * single "cannot resend" would send an administrator to the wrong list.
     */
    private function assertOpen(Invitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw new CannotActOnInvitation(
                'That invitation has already been accepted — they have an account. If they '
                .'cannot get in, a password reset is what they need.'
            );
        }

        if ($invitation->revoked_at !== null) {
            throw new CannotActOnInvitation(
                'That invitation was withdrawn. Send a new one if you have changed your mind, '
                .'so the record still shows what was decided and when.'
            );
        }
    }
}
