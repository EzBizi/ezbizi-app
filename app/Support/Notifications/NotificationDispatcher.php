<?php

namespace App\Support\Notifications;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\NotificationNotice;
use App\Support\Access\ScopeReach;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Deciding who to tell, and telling them (plan §12).
 *
 * ── Why the audience is resolved from REACH ──────────────────────
 *
 * An automation says "notify somebody about this job". The only defensible answer
 * to "who" is the people who could have seen the job anyway: sending a
 * notification about a record whose contents the recipient is not allowed to
 * read would be a leak dressed up as a feature, and the title alone often gives
 * the record away.
 *
 * So reach decides the audience, exactly as it decides the job list (§4). A
 * franchise-level user hears about their franchise; a company-level user hears
 * about everything beneath them, Sub Companies included.
 */
class NotificationDispatcher
{
    /**
     * A hard ceiling per dispatch.
     *
     * An organization-wide rule firing on every job in a busy franchise would
     * otherwise write a row per user per event, and the first anyone notices is
     * the bell showing four thousand.
     */
    private const MAX_RECIPIENTS = 200;

    /**
     * The email ceiling, deliberately far below the row ceiling.
     *
     * Fifty rather than two hundred because the two media fail differently: a
     * bell nobody wanted is dismissed, and a mailshot nobody wanted is a
     * complaint against a sending reputation shared by every organization on
     * the platform. Fifty is high enough that no ordinary audience reaches it
     * and low enough to stop a rule firing per job from mailing a franchise.
     *
     * Exceeding it sends nothing rather than a subset — see alsoByEmail.
     */
    private const MAX_EMAIL_RECIPIENTS = 50;

    /**
     * Which setting switches each `source` off, and the reason this is a map
     * rather than a string built from the source name.
     *
     * A built key — "notifications.{$source}_enabled" — would ask the registry
     * for a definition that may not exist the moment any module invents a new
     * source, and `getOrFail` would turn "we have not written a toggle for this
     * yet" into an exception thrown from inside a queue worker, losing the
     * notification. A source absent from this map is ALLOWED (see [allows]),
     * which is the same fail-open direction the version gate takes for a
     * platform it does not recognise.
     *
     * `system` is absent deliberately and permanently: it is the source that
     * carries "your trial ends tomorrow".
     */
    private const SOURCE_SETTINGS = [
        'automation' => 'notifications.automation_enabled',
        'hr' => 'notifications.hr_enabled',
    ];

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly ScopeReach $reach,
    ) {}

    /**
     * @param  Model|null  $subject  what it is about; also decides the audience
     * @return int how many people were told
     */
    public function dispatch(
        string $title,
        ?string $body = null,
        ?Model $subject = null,
        ?User $onlyTo = null,
        string $source = 'system',
        ?string $automationId = null,
        ?string $eventId = null,
    ): int {
        if (! $this->allows($source, $subject)) {
            return 0;
        }

        $recipients = $onlyTo !== null
            ? collect([$onlyTo])
            : $this->audienceFor($subject);

        if ($recipients->isEmpty()) {
            return 0;
        }

        $told = $recipients->take(self::MAX_RECIPIENTS);
        $scope = $this->settingsScope($subject);

        $rows = $told->map(fn (User $user) => [
            'id' => (string) Str::uuid7(),
            'organization_id' => $this->tenant->idOrFail(),
            'user_id' => $user->getKey(),
            'source' => $source,
            'title' => $title,
            'body' => $body,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'automation_id' => $automationId,
            'event_id' => $eventId,
            'created_at' => now(),
        ])->all();

        /*
         * THE BELL IS A CHANNEL NOW, NOT THE WHOLE SYSTEM.
         *
         * `notifications.in_app_enabled` decides the ROW and nothing else. It
         * spent August calling itself "the master switch" in its own help — I
         * wrote that sentence, and it was true and complete about a system with
         * one channel. It never contemplated a second one, and reading a
         * promise about email out of it is reading intent into a sentence
         * written before the question existed.
         *
         * The key is NAMED `in_app_enabled`. Somebody who unticks "Show in-app
         * notifications" and silently stops receiving MAIL has been lied to by
         * the name, and silently losing mail is the worse of the two surprises.
         *
         * What is genuinely cross-channel is the SOURCE map, which allows()
         * asks — a company that silences automations is silenced on every
         * channel, now and for any channel added later. Nothing is lost by the
         * split, including the case my old sentence was protecting: `system`
         * has no source toggle deliberately, and is still switchable off on
         * each channel independently by that channel's own key.
         */
        $rowsWritten = 0;

        if ($this->channelOn('notifications.in_app_enabled', $scope)) {
            // One insert. A loop of saves would be one query per recipient, and
            // this runs inside a queue worker handling every event on the
            // platform.
            Notification::insert($rows);
            $rowsWritten = count($rows);
        }

        $emailed = $this->alsoByEmail($told, $title, $body, $scope);

        /*
         * HOW MANY PEOPLE WERE TOLD — on any channel, not how many rows exist.
         *
         * ActionRunner renders this to a rule author as "Told 5 people", so it
         * has to count people rather than artefacts. `max` rather than a sum
         * because the two sets overlap: everyone emailed was also belled unless
         * the bell is off, so adding them would report twice the audience.
         *
         * It is max rather than simply the row count because the row count is
         * zero when the bell is switched off, and "Told 0 people" is a lie told
         * to somebody whose 5 colleagues just received an email about it.
         */
        return max($rowsWritten, $emailed);
    }

    /**
     * The same notification again, as mail, when the organization asks for it.
     *
     * -- This is the half the settings screen has been promising --
     *
     * `notifications.email_enabled` has existed since August with a sentence
     * shown to administrators: "Takes effect when notifications can be sent by
     * email. They are only shown inside the app today, so switching this off
     * changes nothing." It was true until SES came out of the sandbox on
     * 2026-09-14 and Jose asked for the path to be built. Until then a send
     * reached only hand-verified addresses and reported success for every other
     * recipient, so wiring this earlier would have been worse than not having
     * it: silence that looks like delivery.
     *
     * -- Same audience, same ceiling, same scope, no second decision --
     *
     * It takes the collection dispatch() already resolved and already capped,
     * rather than asking who should hear a second time. Two derivations of "who
     * hears about this" is the disagreement `franchiseIdFor` exists to prevent
     * one level up, and here it would mail somebody the bell deliberately
     * skipped.
     *
     * The source toggles are not re-asked: allows() has already refused
     * everything it refuses, so a source switched off sends neither a row nor a
     * letter. `notifications.in_app_enabled` is NOT asked here at all — it
     * governs the row, and this is not the row. See dispatch().
     *
     * -- Why this has its own ceiling, lower than the bell's --
     *
     * MAX_RECIPIENTS is 200 and that number was chosen for ROWS. It does not
     * transfer. 200 rows is a bell showing 200; 200 emails is a mailshot, sent
     * per event, against an SES reputation that bounces and complaints damage
     * permanently and which no amount of later care repairs. Rows are cheap,
     * dismissible, and invisible outside the company. Mail is none of those.
     *
     * -- And why exceeding it sends NOTHING rather than the first fifty --
     *
     * Truncating would mail an arbitrary subset chosen by collection order, and
     * "why did Bob get an email about this and I did not" has no answer anybody
     * can give. A fan-out this wide is a misconfigured rule rather than a
     * legitimate audience, so the honest response is to leave it to the bell —
     * which told everybody — and say so loudly enough to be found.
     *
     * ponytail: one flat ceiling for every source. If a real audience ever
     * needs more than this, the fix is a per-source number or a digest, not a
     * bigger constant.
     *
     * @return int how many were actually emailed
     *
     * -- One thing deliberately left alone --
     *
     * `dispatchUnique` does not get this. It has NO callers: measured across
     * app/ and tests/, only this file and docs/notifications-contract.md
     * mention it at all. Wiring mail into it would be delivery nobody can
     * reach, and its own question -- does a suppressed duplicate still mail? --
     * has no caller to answer it. It goes to whoever gives it one.
     *
     * @param  Collection<int, User>  $recipients  already capped by the caller
     */
    private function alsoByEmail(Collection $recipients, string $title, ?string $body, Model $scope): int
    {
        if (! $this->channelOn('notifications.email_enabled', $scope)) {
            return 0;
        }

        if ($recipients->count() > self::MAX_EMAIL_RECIPIENTS) {
            Log::warning('Notification not emailed: audience above the email ceiling.', [
                'audience' => $recipients->count(),
                'ceiling' => self::MAX_EMAIL_RECIPIENTS,
                'title' => $title,
                'organization_id' => $this->tenant->id(),
            ]);

            return 0;
        }

        // Resolved here rather than inside the queued notice: reading it there
        // would read it at DELIVERY time, minutes after the decision it belongs
        // to, so a reply-to changed in between would apply retroactively to
        // mail that was already on its way.
        $replyTo = trim((string) $this->settings->get('notifications.reply_to_email', $scope));

        $notice = new NotificationNotice($title, $body, $replyTo === '' ? null : $replyTo);

        $sent = 0;

        foreach ($recipients as $recipient) {
            try {
                // notify() rather than the Notification facade: the model class
                // in this file is App\Models\Notification, and aliasing a facade
                // beside it to save one loop is how somebody later writes the
                // wrong one.
                $recipient->notify($notice);
                $sent++;
            } catch (Throwable $exception) {
                /*
                 * CAUGHT BECAUSE THE BELL HAS ALREADY SUCCEEDED.
                 *
                 * The rows are inserted by the time this runs. Letting a mail
                 * failure escape would fail the automation action that raised
                 * it, and a failed action is RETRIED — re-entering dispatch()
                 * and inserting the whole audience's notifications a second
                 * time. A misconfigured mailer would show up as duplicate bells,
                 * which is a symptom nobody would trace back to email.
                 *
                 * Logged rather than swallowed, because this table records no
                 * delivery state at all: `notifications` has created_at and
                 * read_at and nothing else, so without this line a failed send
                 * leaves no trace anywhere in EzBizi. That is exactly the
                 * accepted-and-dropped silence the SES sandbox produced for
                 * three weeks, and it would be worse to ship it deliberately.
                 */
                Log::warning('Notification email failed for one recipient.', [
                    'user_id' => $recipient->getKey(),
                    'title' => $title,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return $sent;
    }

    /** One channel's own switch, resolved against the scope dispatch() chose. */
    private function channelOn(string $key, Model $scope): bool
    {
        return (bool) $this->settings->get($key, $scope);
    }

    /**
     * Null when this organization has the source switched off.
     *
     * Null rather than a silently-unsaved model: the caller holds a delivery
     * intent it has to settle either way, and "suppressed" and "delivered" are
     * different answers to give it. A Notification returned for a row that was
     * never written would have the intent recording a delivery that did not
     * happen — which is precisely the evidence that table exists to keep.
     */
    public function dispatchUnique(
        User $recipient,
        string $title,
        ?string $body,
        Model $subject,
        string $source,
        string $deliveryKey,
    ): ?Notification {
        if (! $this->allows($source, $subject)) {
            return null;
        }

        return Notification::query()->firstOrCreate([
            'user_id' => $recipient->id,
            'source' => $source,
            'delivery_key' => $deliveryKey,
        ], [
            'organization_id' => $this->tenant->idOrFail(),
            'title' => $title,
            'body' => $body,
            'subject_type' => $subject::class,
            'subject_id' => $subject->getKey(),
            'created_at' => now(),
        ]);
    }

    /**
     * @return Collection<int, User>
     */
    private function audienceFor(?Model $subject): Collection
    {
        $users = User::query()->where('is_active', true)->with('assignments')->get();

        /*
         * A RECORD THAT KNOWS WHERE IT SITS IS ASKED, RATHER THAN GUESSED AT.
         *
         * The column sniff below finds a job and a customer and nothing else,
         * and until Human Resources raised events that was every record that
         * reached here. An invitation carries §4's XOR across three `assigned_*`
         * columns and an onboarding carries no scope column at all — so both
         * fell through to the organization-level branch, and a rule saying
         * "notify" on a completed onboarding would have told EVERY ACTIVE USER
         * in the business that a named person's contract had been signed.
         *
         * That is precisely the leak this class's own header says reach exists
         * to prevent, arriving through a record shape the sniff was never
         * written for. CarriesAutomationScope is the same question asked out
         * loud, so it is asked here as well as by the automation engine — one
         * answer, two readers, rather than a second derivation that can disagree
         * with who is allowed to open the record.
         *
         * A record with no franchise and no company belongs to the organization,
         * and here that means the people who STAND at the organization rather
         * than everybody in it — the same conservative direction Onboarding's
         * own scope() takes when it has nothing narrower to go on.
         */
        if ($subject instanceof CarriesAutomationScope) {
            [$type, $id] = $subject->automationScope();

            [$type, $id] = $type === null
                ? [Assignment::SCOPE_ORGANIZATION, $this->tenant->idOrFail()]
                : [$type, $id];

            return $users
                ->filter(fn (User $user) => $this->reach->covers($user, $type, $id))
                ->values();
        }

        $franchiseId = $this->franchiseIdFor($subject);

        if ($franchiseId === null) {
            // Nothing to scope by — an organization-level event. Everyone active
            // hears about it, which is right for "your trial ends tomorrow" and
            // is why this path exists at all.
            return $users;
        }

        $franchise = Franchise::query()->find($franchiseId);

        if ($franchise === null) {
            return collect();
        }

        // Reach, not membership. Assignment does not cascade but authority does
        // (§4), so a company-level manager hears about their Sub Companies'
        // franchises without being assigned to any of them.
        return $users->filter(fn (User $user) => $user->canActOnFranchise($franchise))->values();
    }

    /**
     * Is this organization sending this kind of notification at all?
     *
     * ── Why this is INSIDE dispatch rather than beside it ────────────
     *
     * It used to be a public `enabled()` that call sites were expected to ask
     * first, and its docblock claimed that putting it here "means a module added
     * later cannot forget to". That was never true of a method somebody has to
     * remember to call: of the three producers in the system, exactly one — the
     * HR controller — ever called it. An organization that switched in-app
     * notifications off went on receiving every automation notify and every task
     * assignment, and the switch that was supposed to stop them had been sitting
     * in the settings screen looking live since the day it shipped.
     *
     * So the check moved to the only place a caller cannot skip: the path the
     * row is written on. The sentence in the old docblock is now the truth
     * rather than the intention.
     */
    public function allows(string $source, ?Model $subject = null): bool
    {
        $scope = $this->settingsScope($subject);

        $key = self::SOURCE_SETTINGS[$source] ?? null;

        if ($key !== null && ! $this->channelOn($key, $scope)) {
            return false;
        }

        /*
         * AND AT LEAST ONE CHANNEL LEFT TO SAY IT ON.
         *
         * This is not the source question, and it is here for a caller outside
         * this file: ActionRunner:1194 asks allows() to explain a zero to a rule
         * author, choosing between "nobody has reach over that record" and "this
         * company has automation notifications switched off". Those send someone
         * debugging a rule to opposite places.
         *
         * Splitting in_app_enabled into a channel switch opened a third case —
         * source on, both channels off — and without this clause it would have
         * landed on the reach sentence, sending somebody to audit permissions
         * over a settings screen. Answering it here keeps that caller correct
         * without reaching into a file I do not own, and keeps allows() meaning
         * the one thing its name claims: will this be told at all.
         */
        return $this->channelOn('notifications.in_app_enabled', $scope)
            || $this->channelOn('notifications.email_enabled', $scope);
    }

    /**
     * Which scope's answer counts — the subject's franchise where there is one.
     *
     * Not always the organization. These settings are declared franchise-
     * overridable, so resolving every one of them against the organization would
     * leave a franchise's override switched on in the screen and dead in the
     * code — a second setting that looks live and is not, which is the whole
     * defect above repeated one level down.
     */
    private function settingsScope(?Model $subject): Model
    {
        $franchiseId = $this->franchiseIdFor($subject);

        $franchise = $franchiseId === null ? null : Franchise::query()->find($franchiseId);

        return $franchise ?? $this->tenant->get() ?? throw new RuntimeException(
            'A notification needs a tenant in scope to resolve whether it is switched on.'
        );
    }

    /**
     * The franchise a record sits in, or null for one that sits above them all.
     *
     * Asked in one place because two readers need the same answer: who hears
     * about the record, and whose setting decides whether they hear at all. Two
     * derivations of "which franchise is this" could disagree, and the way they
     * would disagree is a notification sent under one franchise's rules to an
     * audience assembled under another's.
     */
    private function franchiseIdFor(?Model $subject): ?string
    {
        if ($subject instanceof CarriesAutomationScope) {
            [$type, $id] = $subject->automationScope();

            return $type === Assignment::SCOPE_FRANCHISE ? $id : null;
        }

        /*
         * A franchise is in itself, and nothing else here would have said so.
         *
         * The sniff below looks for a `franchise_id` column, which a Franchise
         * does not have — its own key IS the answer. Without this branch a
         * notification whose subject is a franchise reads as having no franchise
         * at all, and the two callers take that to mean the organization: the
         * setting resolves against the organization rather than the franchise's
         * own override, and the audience becomes EVERY ACTIVE USER rather than
         * the people with reach over that one franchise.
         */
        if ($subject instanceof Franchise) {
            return $subject->getKey();
        }

        return $subject?->getAttribute('franchise_id')
            ?? $subject?->getAttribute('owning_franchise_id');
    }
}
