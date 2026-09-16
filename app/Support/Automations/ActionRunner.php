<?php

namespace App\Support\Automations;

use App\Mail\InspectionReportMail;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\Lead;
use App\Support\Inventory\InventoryItemStatus;
use App\Support\Inventory\InventoryUnit;
use App\Support\Fleet\VehicleFuelType;
use App\Support\Fleet\VehicleBodyType;
use App\Models\InventoryItem;
use App\Models\Mailbox;
use App\Models\Organization;
use App\Models\PhoneCall;
use App\Models\PhoneNumber;
use App\Models\TrainingAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Audit\AuditLogger;
use App\Support\Email\DealtState;
use App\Support\Fleet\VehicleStatus;
use App\Support\Inspection\ReportPdf;
use App\Support\Mail\CannotSendMail;
use App\Support\Mail\MailDispatcher;
use App\Support\Mail\MailTransport;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use App\Support\TimeClock\TimeClock;
use App\Support\Training\AssignmentWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\PhoneMessage;
use App\Support\Phone\SmsCompliance;
use App\Support\Phone\TwilioGateway;

/**
 * Performing one action from an automation (plan §5.5).
 *
 * Every action returns a RESULT rather than throwing on a business failure —
 * "the webhook returned 500" is something the run log has to record, not
 * something that should abort the remaining actions. Genuine programming errors
 * (an unknown action type, a field that does not exist) still throw, because
 * those mean the rule itself is wrong.
 *
 * Actions that change data emit their own events through the emitter, carrying
 * the causing event so the cascade shares one root, one depth count and one
 * budget. That is what stops A→B→A.
 */
class ActionRunner
{
    /**
     * What a rule may set, per model. Explicit, not derived from $fillable.
     *
     * isFillable was the wrong guard. Customer::owning_franchise_id and
     * Job::franchise_id, customer_id and currency are all fillable — and the
     * HTTP API deliberately REFUSES all of them, because moving a customer's
     * accountability is a separate audited act (§5.2) and a job that changes
     * franchise rewrites two franchises' history. An automation could do
     * every one of those silently, skipping the authorization check, the
     * tenant-existence validation and the audit entry that exist for that.
     *
     * So: only fields where an automated change is meaningful and reversible.
     * Anything with its own endpoint belongs to that endpoint.
     *
     * ── Why the WORDING lives here too ───────────────────────────
     *
     * This is a security invariant, so it stays in code and is PUBLISHED rather
     * than made editable (§2.1). Publishing means a picker, and a picker needs
     * names: `scheduled_for` and `amount_minor` are wire names, and the person
     * choosing between them runs a chimney business.
     *
     * The client DOES name some of these on its own forms — jobs_screen calls
     * `status` "Status" and `amount_minor` "Price", customers_screen names
     * `name`, `email` and `phone` — but those are the words for a person filling
     * in one record by hand, and they are neither complete (nothing labels
     * `duration_minutes`) nor always right here: a rule writes raw minor units,
     * so "Price" would be an invitation to type 199.99 and get \$1.99. The data
     * export is the only other place that lists these columns and it prints the
     * keys raw. So the picker's wording had to be written somewhere.
     *
     * It lives beside the keys because a second array mapping field to name is
     * the one thing this whole arrangement exists to avoid. One array carries
     * both, which makes a field without a name impossible instead of merely
     * unlikely, and makes adding a field a single edit.
     *
     * @var array<class-string<Model>, array<string, string>> model => field => label
     */
    private const WRITABLE = [
        Job::class => [
            'title' => 'Title',
            'description' => 'Description',
            'status' => 'Status',
            'scheduled_for' => 'Date and time',
            'duration_minutes' => 'Duration in minutes',
            // Minor units, deliberately named as such. The job form takes
            // DOLLARS and converts (see the client's parseAmountMinor, and the
            // hundredfold error it was written to stop); a rule writes the
            // column directly, so calling it "Price" here would invite exactly
            // that mistake through the other door.
            'amount_minor' => 'Amount in cents',
        ],
        Customer::class => [
            'name' => 'Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'notes' => 'Notes',
            'is_active' => 'Active',
            'address_line1' => 'Address line 1',
            'address_line2' => 'Address line 2',
            'city' => 'City',
            'state' => 'State',
            'postal_code' => 'Postal code',
        ],
        Vehicle::class => [
            'plate' => 'Plate',
            'colour' => 'Colour',
            'odometer_miles' => 'Odometer (miles)',
            'next_service_on' => 'Next service date',
            'next_service_miles' => 'Next service miles',
        ],
    ];

    /**
     * The fields a rule may set on a model, and what each one is called.
     *
     * Public because the save-time check and the catalogue both need it, and the
     * alternative — either of them keeping its own list — is how a rule came to
     * be accepted with `field: organization_id` and then refused on every single
     * run, forever. One list, three readers.
     *
     * @param  class-string|null  $model
     * @return array<string, string> field => label, empty when nothing is writable
     */
    /**
     * The values a condition can be written against, for fields with a fixed set.
     *
     * Two shapes, and the difference is about DRIFT rather than style:
     *
     *   'field' => EnumClass::class   read at runtime from ::cases(). A module
     *                                 adding a case is offered immediately and
     *                                 cannot leave this list half-complete.
     *   'field' => [value => label]   only where there is no enum to read - a
     *                                 set of string constants - and then a test
     *                                 pins it against the model so the same
     *                                 drift fails loudly instead.
     *
     * ONLY FOR SETS FIXED IN CODE. Job status is deliberately absent: it is
     * RENAMEABLE PER TENANT (SettingsController::renameJobStatuses), so the
     * honest list is a settings read rather than a constant, and a hardcoded
     * dropdown would show one business another business's words.
     *
     * A half-complete list is worse than a text box - it looks authoritative
     * while quietly excluding the value somebody needed.
     */
    private const FIELD_VALUES = [
        Lead::class => [
            'source' => [
                'local_services' => 'Google Local Services',
                'thumbtack' => 'Thumbtack',
                'website_chat' => 'Website live chat',
                'website_form' => 'Website form',
            ],
            'status' => [
                'new' => 'New',
                'booked' => 'Booked',
                'archived' => 'Archived',
                'not_a_lead' => 'Not a real enquiry',
            ],
        ],
        Vehicle::class => [
            'body_type' => VehicleBodyType::class,
            'fuel_type' => VehicleFuelType::class,
            'status' => VehicleStatus::class,
        ],
        InventoryItem::class => [
            'unit' => InventoryUnit::class,
            'status' => InventoryItemStatus::class,
        ],
    ];

    /**
     * Words the humaniser gets wrong, and nothing else.
     *
     * `out_of_service` becomes "Out of service" on its own; `suv` becomes "Suv".
     */
    private const VALUE_LABELS = [
        'suv' => 'SUV',
        'ft' => 'Feet',
        'in' => 'Inches',
        'gal' => 'Gallons',
        'lb' => 'Pounds',
        'in_shop' => 'In the shop',
    ];

    /** @return array<string, array<string, string>> field => [value => label] */
    public static function valuesOn(?string $model): array
    {
        if ($model === null || ! isset(self::FIELD_VALUES[$model])) {
            return [];
        }

        $out = [];

        foreach (self::FIELD_VALUES[$model] as $field => $set) {
            if (is_array($set)) {
                $out[$field] = $set;

                continue;
            }

            foreach ($set::cases() as $case) {
                $value = (string) $case->value;
                $out[$field][$value] = self::VALUE_LABELS[$value]
                    ?? ucfirst(str_replace('_', ' ', $value));
            }
        }

        return $out;
    }

    public static function writableOn(?string $model): array
    {
        return $model === null ? [] : (self::WRITABLE[$model] ?? []);
    }

    /**
     * Which records a rule may move to a named status.
     *
     * Jobs already allow it via update_field. Vehicles go through
     * VehicleStatus::apply. Anything else returns a result at run time, so the
     * save-time check has to ask the same question or a Customer rule would
     * save and fail on every run.
     *
     * @param  class-string|null  $model
     */
    public static function canChangeStatus(?string $model): bool
    {
        return $model === Job::class || $model === Vehicle::class;
    }

    /**
     * Field changes that mean something more specific than "updated".
     *
     * The controllers emit these; an automated change must emit them too, or
     * "when a job is completed" — the canonical rule the plan names — silently
     * does not fire when another rule is what completed it.
     */
    private const SECONDARY_TRIGGERS = [
        Job::class => [
            'status' => 'job.status_changed',
            'scheduled_for' => 'job.scheduled',
        ],
        Customer::class => [],
    ];

    public function __construct(
        private readonly EventEmitter $emitter,
        private readonly OutboundWebhook $webhook,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
        private readonly RecordNumberGenerator $numbers,
        private readonly TimeClock $timeClock,
        private readonly SmsCompliance $smsCompliance,
        private readonly TwilioGateway $twilio,
        private readonly AssignmentWriter $trainingAssignments,
        private readonly DealtState $dealt,
        private readonly MailDispatcher $mail,
        private readonly ReportPdf $reportPdf,
    ) {}

    /**
     * @return array{type: string, ok: bool, detail: string, skipped?: bool}
     */
    public function run(
        array $action,
        AutomationEvent $event,
        ?Model $subject,
        bool $isTest = false,
        ?Automation $automation = null,
        int $actionIndex = 0,
        ?string $idempotencyKey = null,
    ): array {
        $type = $action['type'] ?? null;

        if ($type === null) {
            throw new InvalidArgumentException('An automation action needs a type.');
        }

        // Test mode performs nothing (§5.5). It reports what it WOULD do, which
        // is the only way to try a rule against real data without sending a
        // customer an email about a job from last March.
        if ($isTest) {
            return [
                'type' => $type,
                'ok' => true,
                'skipped' => true,
                'detail' => 'Test run — not performed.',
            ];
        }

        return match ($type) {
            'update_field' => $this->updateField($action, $event, $subject),
            'change_status' => $this->changeStatus($action, $event, $subject),
            'webhook' => $this->callWebhook($action, $event, $subject, $idempotencyKey),
            'notify' => $this->notify($action, $event, $subject),

            // ActionDefinition::type() keeps everything after the first dot, so
            // phone.voicemail.mark_dealt arrives here as voicemail.mark_dealt.
            'voicemail.mark_dealt' => $this->markVoicemailDealt($event, $subject),
            'message.mark_dealt' => $this->markMessageDealt($event, $subject),
            'missed.create_job' => $this->createJobFromMissed($event, $subject),
            'sms.send' => $this->sendSms($action, $event, $automation),

            'time_clock.clock_in' => $this->punch($event, $subject, in: true),
            'time_clock.clock_out' => $this->punch($event, $subject, in: false),

            'training.assign' => $this->assignTraining($action, $event, $subject, $automation),
            'inspection_report.email' => $this->emailInspectionReport($action, $subject),

            default => throw new InvalidArgumentException(
                "Unknown automation action '{$type}'. Actions are declared by modules "
                .'in code (§5.5) — a rule cannot invent one.'
            ),
        };
    }

    /**
     * The actor and scope an automation action runs as — its own creator,
     * standing exactly where they stood when they saved the rule.
     *
     * @return array{0:User,1:ViewScope}
     */
    private function taskContext(?Automation $automation): array
    {
        if ($automation === null) {
            throw new InvalidArgumentException('This automation action requires its rule identity.');
        }
        $actor = User::query()->where('is_active', true)->find($automation->created_by);
        if ($actor === null) {
            throw new InvalidArgumentException('The person who created this automation is no longer active.');
        }

        return [$actor, ViewScope::forStoredAutomationScope($actor, $automation->scope_type, $automation->scope_id)];
    }

    /**
     * Record that a voicemail has been picked up (dealt_at). EzBizi only — no Dial.
     *
     * @return array{type: string, ok: bool, detail: string}
     */
    /**
     * Clock the event's person in or out.
     *
     * Subject-bound, so the rule needs an event that is ABOUT a person — a
     * clock trigger, or `user.activated`. A rule wanting "clock out everybody
     * still open at 11pm" needs a scheduled trigger and a picked person, and
     * neither exists yet; refusing clearly beats a silently-empty run.
     *
     * TimeClock is called rather than reimplemented, so the organization
     * switch, the grant, the row lock, the unique index, the audit entry and
     * the emitted event are the same ones a person's punch goes through. The
     * asymmetry between the two is deliberate and belongs to that class: clock
     * IN refuses when the organization has the feature off or the person lacks
     * the grant, clock OUT only needs an open shift, because a shift left open
     * by somebody whose access changed still has to be closable (design §3.10).
     * A rule inherits both without restating either.
     */
    private function punch(AutomationEvent $event, ?Model $subject, bool $in): array
    {
        $type = $in ? 'time_clock.clock_in' : 'time_clock.clock_out';

        if (! $subject instanceof User) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'That record is not a person, so there is nobody to clock '
                    .($in ? 'in.' : 'out.'),
            ];
        }

        try {
            $in
                ? $this->timeClock->clockIn($subject, $event)
                : $this->timeClock->clockOut($subject, $event);
        } catch (ValidationException $e) {
            // "Already clocked in" and "not clocked in" are the ordinary result
            // of a rule firing on somebody whose state already matches, not a
            // fault. The run log says which, in the same sentence a person gets.
            return [
                'type' => $type,
                'ok' => false,
                'detail' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            ];
        } catch (HttpException $e) {
            // clockIn aborts 403 when the person does not hold time_clock.view.
            return [
                'type' => $type,
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        return [
            'type' => $type,
            'ok' => true,
            'detail' => $in
                ? "Clocked {$subject->name} in."
                : "Clocked {$subject->name} out.",
        ];
    }

    /**
     * Send a text from one of the company's lines.
     *
     * ── Why the line and the destination are both stated in the rule ──
     *
     * This is the one Phone action that is NOT about the record the event was
     * about. It exists so a rule on something with no phone in it — an email
     * arriving, a job changing status — can still send a text. There is
     * therefore nothing to infer the numbers from, and inferring is how a
     * customer's text ends up going to whoever happened to be in the payload.
     *
     * ── Three refusals, and each says which one it is ────────────────
     *
     * A rule that silently does nothing is worse than one that fails, because
     * the run log is the only place anybody can see either. So a missing line,
     * a line belonging to another company, and a line not cleared for
     * messaging are three distinct answers rather than one shrug.
     */
    /**
     * Send a finished inspection to the customer it was carried out for.
     *
     * ── Why asTenant and never platform ─────────────────────────
     *
     * The sender is the company, writing to their own customer about work they
     * did. `MailDispatcher::platform()` is EzBizi speaking as EzBizi, and a
     * report arriving from our address would be addressed to somebody who has
     * no relationship with us at all. So this is the FIRST caller of
     * `asTenant()` — the screen at Settings > Sending Identity has been saying
     * "nothing sends from this address yet" on the grounds that Marketing's
     * newsletter would be first, and that sentence stops being true when this
     * ships.
     *
     * ── Every refusal is a run-log line, not an exception ───────
     *
     * STALE TWICE, NOW CORRECT AS OF 2026-09-15. `asTenant` THROWS
     * UNCONDITIONALLY — a bare `throw new CannotSendMail(...)` with no branch
     * above it, under Jose's ruling that EzBizi's own mail carries signing in,
     * password resets and joining messages and nothing else.
     *
     * The two dead readings, kept because each was believed by several sessions
     * and each is reachable from the method NAME: it does not throw "whenever a
     * domain is unproven" (the original text), and it does not throw "only when
     * the transport REQUIRES domain proof" (the first correction, true for one
     * day). Read the method, never this paragraph — including this version of
     * it. Mail is another module's and the answer has changed twice in as many
     * days.
     *
     * WHY THE PLATFORM CHECK BELOW STAYS ANYWAY. It is redundant while the
     * throw is unconditional, and it is what refuses if that policy is ever
     * relaxed — which it may be, now that a business can connect its own mail
     * server (fe37ad4a, d2a256e1). A guard deleted as redundant under a policy
     * is armed to be missing when the policy moves. The rest of this paragraph
     * describes the world it was written for and is why it must not go:
     *
     * The gap is the resolver, not the check. `SendingIdentityResolver::for()`
     * walks the cascade and, finding no row at any link, ends
     * `return $this->platform->identity();`. `MailTransport::Platform`'s
     * `requiresDomainProof()` is false, so `canSend()` returns true on the
     * first branch and the refusal never fires at all. A tenant who has
     * configured nothing does not get a throw; they get a send, as EzBizi.
     *
     * THAT IS WHY THE EXPLICIT `MailTransport::Platform` CHECK EXISTS BELOW
     * rather than leaning on this throw. Anybody deleting it as redundant
     * should read the return path rather than the method name: all three wrong
     * beliefs — that asTenant binds a tenant to their own domain, that
     * `isTenantChoosable()` prevents it, that `canSend()` checks verification —
     * were reasoned from a name or from a neighbouring guard, and none of them
     * covered the resolver landing on Platform with nobody having chosen it.
     *
     * The throw is still real and still caught, because an identity that DOES
     * require proof and lacks it raises — right for a controller and wrong
     * here: an automation that raises kills the rule and tells the tenant
     * nothing they can act on. Caught and
     * returned as `ok: false` carrying the dispatcher's own sentence, which
     * already says what to do about it.
     *
     * The same for a customer with no email address. That is not a fault — a
     * business can hold customers it has only ever phoned — so it is reported
     * and not raised, and the rule stays on for the customers it can reach.
     *
     * ── The address is the customer's, and not configurable ─────
     *
     * A `to` parameter would make this an arbitrary mail sender with an
     * inspection attached, and the report contains a named person's address
     * and photographs of their property. The recipient is the customer on the
     * record, resolved when the rule fires, which is the only address the
     * subject of the document has consented to be at.
     */
    private function emailInspectionReport(array $action, ?Model $subject): array
    {
        $type = 'inspection_report.email';

        /*
         * -- HELD BY JOSE'S RULING OF 2026-09-14, AND THIS IS THE REAL LEVER --
         *
         * His words, to the Automations session, who asked directly whether
         * they covered this action. He answered yes:
         *
         *     "no ezbizi SES is to be used only for EZBIZI Related actions like
         *      notificaitons, account creation, password, resets, etc
         *      at least for now."
         *
         * THE PLATFORM CHECK BELOW DOES NOT COVER THIS, and believing it did is
         * the mistake this comment exists to prevent. That check reads the FROM
         * LINE and refuses when the resolved identity is EzBizi. But
         * MailDispatcher::handOver() ends:
         *
         *     Mail::mailer($this->platform->mailerName())->to($to)->send($message);
         *
         * EVERY message leaves over the platform mailer. A verified tenant
         * identity changes from() and replyTo() and nothing else - the
         * transport, the SES reputation and the 50,000/day quota belong to
         * EzBizi either way. So a fully configured branch would still have
         * mailed a member of the public over EzBizi SES, which is what the
         * ruling forbids, and no From-line guard can see it.
         *
         * WHY THE REFUSAL IS HERE AND NOT IN THE CONST. Dropping
         * inspection_report.email from AutomationsController::IMPLEMENTED_ACTIONS
         * stops anybody building a NEW rule and leaves this arm running every
         * rule already saved - it would look like a fix and not be one. The
         * Automations session made that point and it is right.
         *
         * WHY IT IS FIRST, BEFORE ANY LOOKUP. Until somebody configures a
         * sending identity the Platform check below refuses everything anyway,
         * so this was latent rather than live: production holds two
         * organizations and zero rows in sending_identities. The hazard is the
         * SEQUENCE. "Configure a sending identity for A-1 Group" sits on Jose's
         * list because I put it there, and the moment he does it a guard
         * written for a different question stops refusing and this starts
         * sending - silently, correctly by its own lights, against a ruling
         * made after it shipped. A refusal that depends on nothing cannot be
         * switched on by somebody changing unrelated configuration.
         *
         * TO LIFT IT: delete this block. Everything below is intact and still
         * tested. The condition to check first is whether mail can leave over a
         * transport that is not EzBizi - a per-tenant MAILER, rather than the
         * per-tenant From line that exists today.
         */
        return [
            'type' => $type,
            'ok' => false,
            'detail' => 'Emailing inspection reports to customers is switched off. The '
                .'EzBizi mail service carries EzBizi messages only - sign-ins, password '
                .'resets and account notices - and a report to a customer would go out '
                .'over it however this branch is configured.',
        ];

        if (! $subject instanceof InspectionReport) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'This action only works on a submitted inspection, so nothing was sent.',
            ];
        }

        // withTrashed on the customer: a report outlives a binned customer, and
        // the address is still the one the work was done for.
        $subject->loadMissing(['customer' => fn ($q) => $q->withTrashed()]);

        $to = trim((string) ($subject->customer?->email ?? ''));

        if ($to === '') {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'That customer has no email address on file, so nothing was sent.',
            ];
        }

        $scope = $this->sendingScopeFor($subject);

        if ($scope === null) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'This report does not say which branch it belongs to, so there is '
                    .'no address to send it from.',
            ];
        }

        /*
         * REFUSE RATHER THAN SEND AS EZBIZI, and this is not what asTenant does
         * on its own.
         *
         * `MailIdentityResolver::for()` falls back to the PLATFORM identity when
         * no row exists at any level of the cascade, and that identity is
         * Verified with a transport whose `requiresDomainProof()` is false — so
         * `canSend()` is true and `asTenant` hands the message over happily. A
         * tenant who has configured nothing would have their customer receive an
         * inspection report from EzBizi's address.
         *
         * That is wrong twice: the customer has no relationship with us, and it
         * discloses which software the company runs to somebody who was only
         * told their chimney was swept. `MailTransport::Platform`'s own label is
         * the word "EzBizi".
         *
         * This is the first caller of asTenant, so nothing has needed the
         * distinction before. Refusing here is deliberately a decision of THIS
         * action rather than a change to the shared dispatcher — the newsletter
         * may reasonably want a different answer, and that is Marketing's call
         * to make when it arrives.
         */
        if ($this->mail->identityFor($scope)->transport === MailTransport::Platform) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'This branch has not set up its own sending address, and a '
                    .'report must not reach a customer from the EzBizi address. Set one '
                    .'up under Settings, Sending Identity.',
            ];
        }

        try {
            $this->mail->asTenant(
                $scope,
                new InspectionReportMail(
                    report: $subject,
                    companyName: (string) $scope->name,
                    pdf: $this->reportPdf->render($subject),
                    note: trim((string) ($action['note'] ?? '')),
                ),
                $to,
            );
        } catch (CannotSendMail $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        $this->audit->log('inspection.emailed', $subject, metadata: ['to' => $to]);

        return [
            'type' => $type,
            'ok' => true,
            'detail' => 'The report was emailed to the customer.',
        ];
    }

    /**
     * The scope whose sending identity this report goes out under.
     *
     * `automationScope()` is reused rather than re-derived, so the branch that
     * SENDS a report is the same branch that gets notified about it and the
     * same one that may read it. Three answers from one method is the point —
     * a separate lookup here could disagree, and the disagreement would look
     * like a mail bug rather than a scope one.
     *
     * Falls back to the organization, which is a real case rather than a
     * defensive one: the §6 identity cascade resolves upwards, so an
     * organization-level address is the correct answer for a report whose
     * branch has not set one.
     */
    private function sendingScopeFor(InspectionReport $report): ?Model
    {
        [$type, $id] = $report->automationScope();

        if ($type === 'franchise' && $id !== null) {
            $franchise = Franchise::query()->find($id);

            if ($franchise !== null) {
                return $franchise;
            }
        }

        return $report->organization_id === null
            ? null
            : Organization::query()->find($report->organization_id);
    }

    private function sendSms(array $action, AutomationEvent $event, ?Automation $automation): array
    {
        $type = 'sms.send';

        $number = PhoneNumber::query()->find($action['number_id'] ?? null);
        if ($number === null) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'No phone line was chosen to send from, so nothing was sent.',
            ];
        }

        // A rule belonging to one company must not send from another's line.
        // The rule's own scope is the boundary; without this, a company that
        // can write rules can send as anybody in the organization.
        if (! $this->numberIsInScope($number, $automation)) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'That phone line does not belong to the company this rule '
                    .'belongs to, so nothing was sent.',
            ];
        }

        $to = trim((string) ($action['to'] ?? ''));
        $body = trim((string) ($action['body'] ?? ''));

        if ($to === '' || $body === '') {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => $to === ''
                    ? 'No number to send to, so nothing was sent.'
                    : 'The message was empty, so nothing was sent.',
            ];
        }

        // The same gate the Messages screen uses, not a second copy of it. A
        // line that is not cleared for messaging will be refused by the
        // carrier anyway; refusing here says so in the run log instead.
        if (! $this->smsCompliance->allowsOutbound($number)) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => SmsCompliance::REFUSAL,
            ];
        }

        $connection = $number->connection;
        if ($connection === null) {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => 'That line has no phone account behind it, so nothing was sent.',
            ];
        }

        try {
            $sent = $this->twilio->sendMessage($connection, (string) $number->e164, $to, $body);
        } catch (Throwable $e) {
            // Twilio's own sentence, because a code we have never seen still
            // explains itself and a rewrite of it does not.
            return [
                'type' => $type,
                'ok' => false,
                'detail' => $e->getMessage() !== ''
                    ? 'Twilio refused it: '.$e->getMessage()
                    : 'Twilio refused to send from this phone line.',
            ];
        }

        $message = PhoneMessage::query()->create([
            'connection_id' => $connection->id,
            'number_id' => $number->id,
            'twilio_sid' => $sent['sid'] ?? null,
            'direction' => PhoneMessage::DIRECTION_OUTBOUND,
            'from_e164' => (string) $number->e164,
            'to_e164' => $to,
            'body' => $body,
        ]);

        $this->audit->log(
            action: 'phone.sms_sent_by_automation',
            target: $message,
            changes: ['after' => ['to' => $to, 'from' => (string) $number->e164]],
            metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
        );

        return [
            'type' => $type,
            'ok' => true,
            'detail' => 'Sent a message from '.$number->e164.' to '.$to.'.',
        ];
    }

    /** Whether this line sits inside the company the rule belongs to. */
    private function numberIsInScope(PhoneNumber $number, ?Automation $automation): bool
    {
        // An organization-wide rule reaches every line the organization holds;
        // BelongsToTenant has already confined the query to one organization.
        if ($automation === null || $automation->scope_type === null) {
            return true;
        }

        return (string) $number->scope_type === (string) $automation->scope_type
            && (string) $number->scope_id === (string) $automation->scope_id;
    }

    /**
     * Give a guide or a test to a person or a role, from a rule (training
     * task-9 brief).
     *
     * Calls the exact `App\Support\Training\AssignmentWriter` that
     * `AssignmentsController::store` calls — a rule and a person take the
     * identical write path, so reach-bounding (`AssignmentReach`) cannot be
     * bypassed by writing a rule instead of clicking a button. The ASSIGNER
     * is the automation's own creator, resolved by [taskContext]: a rule
     * reaches no further than the person who saved it could reach by hand.
     *
     * The assignee comes from the action's own config when a rule author set
     * one (`assignee_type`/`assignee_id` — the picker offers a specific
     * person or role), and falls back to the triggering event's subject when
     * it is a User — the shape the trigger's own description names: "Paired
     * with 'Account activated' this is how an induction arrives on its own"
     * (ModuleServiceProvider).
     *
     * Both of `AssignmentWriter::create`'s failure modes are caught here
     * rather than left to bubble, per this class's own rule that a business
     * refusal is a RESULT and not a throw: a `ValidationException` for a
     * malformed target (neither/both of guide_id and test_id, or nobody
     * named), and an `AuthorizationException` — the same `Refusal` sentence
     * `AssignmentsController` would 403 with — for an assignee outside the
     * creator's own reach.
     */
    private function assignTraining(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        // Both halves of taskContext(), not just the actor —
        // AssignmentWriter::create's own docblock on why: with no request
        // to resolve, its default (ViewScope::forUser) falls through to
        // the RULE CREATOR's stored `navigation.scope` top-bar preference,
        // a fact about their own last click with nothing to do with this
        // rule or its assignee. $scope is the automation's OWN
        // already-resolved scope, computed right here — pass it through
        // rather than silently discarding it.
        [$actor, $scope] = $this->taskContext($automation);

        $assigneeType = $action['assignee_type'] ?? ($subject instanceof User ? TrainingAssignment::USER : null);
        $assigneeId = $action['assignee_id'] ?? ($subject instanceof User ? $subject->getKey() : null);

        $assignmentInput = [
            'guide_id' => $action['guide_id'] ?? null,
            'test_id' => $action['test_id'] ?? null,
            'assignee_type' => $assigneeType,
            'assignee_id' => $assigneeId,
        ];

        // KEY present only when the rule's OWN action config set one — never
        // `$action['due_on'] ?? null`, which would put the key there
        // unconditionally and, for a REPEAT assignment, read as "clear the
        // stored date" every time the rule fires without one configured.
        // AssignmentWriter::create's own docblock, "Absent is not the same
        // as null" (round 3): a rule with no date, firing a second time,
        // must not silently wipe a date a person set by hand.
        if (array_key_exists('due_on', $action)) {
            $assignmentInput['due_on'] = $action['due_on'];
        }

        try {
            $assignment = $this->trainingAssignments->create($actor, $assignmentInput, causedBy: $event, scope: $scope);
        } catch (ValidationException $e) {
            return [
                'type' => 'training.assign',
                'ok' => false,
                'detail' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            ];
        } catch (AuthorizationException $e) {
            return [
                'type' => 'training.assign',
                'ok' => false,
                'detail' => $e->getMessage(),
            ];
        }

        // wasRecentlyCreated distinguishes a genuine insert from a repeat
        // that resolved to the row already there — AssignmentWriter::create's
        // own docblock, "a repeat assignment updates in place, never a
        // second row". A rule firing twice for one instruction should say
        // so, not claim to have assigned it a second time.
        $verb = $assignment->wasRecentlyCreated ? 'Assigned' : 'Already assigned';

        return [
            'type' => 'training.assign',
            'ok' => true,
            'detail' => "{$verb} {$assigneeType}:{$assigneeId} training {$assignment->id}.",
        ];
    }

    /**
     * Mark a message in a shared inbox as handled.
     *
     * ── The message id comes from the PAYLOAD, not the subject ───
     *
     * Every `email.*` trigger carries a Mailbox as its subject, because that is
     * the record §4 scoping hangs off — see MailboxWatcher, which emits the
     * mailbox and puts `message_id` in the payload beside the subject and
     * sender. So this action reads the mailbox from the subject and the message
     * from the event, and refuses plainly when either is missing rather than
     * marking the wrong thing.
     *
     * ── It writes `message_dealt`, never `message_seen` ──────────
     *
     * A rule has no eyes. Recording that it "read" a message would make
     * "Seen by" claim a person saw something nobody saw, and a colleague would
     * skip a customer's mail on the strength of it. Handled and read are
     * different facts at different grains, which is why the table is separate.
     */
    private function markMessageDealt(AutomationEvent $event, ?Model $subject): array
    {
        if (! $subject instanceof Mailbox) {
            return [
                'type' => 'message.mark_dealt',
                'ok' => false,
                'detail' => 'That record is not a shared inbox, so no message can be marked '
                    .'dealt with.',
            ];
        }

        $messageId = $event->payload['message_id'] ?? null;

        if (! is_string($messageId) || $messageId === '') {
            return [
                'type' => 'message.mark_dealt',
                'ok' => false,
                // Says WHICH triggers can carry it, because the fix is to move
                // the rule rather than to change anything about this one.
                'detail' => 'That event does not name a message, so there is nothing to mark. '
                    .'Rules using this action belong on "an email arrived".',
            ];
        }

        $already = $this->dealt->dealtWith($subject, [$messageId]) !== [];

        $this->dealt->markDealt($subject, $messageId, null);

        $this->audit->log(
            action: 'email.message_marked_dealt_by_automation',
            target: $subject,
            changes: ['after' => ['message_id' => $messageId]],
            metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
        );

        return [
            'type' => 'message.mark_dealt',
            'ok' => true,
            'detail' => $already
                ? 'That message was already marked dealt with.'
                : 'Marked the message dealt with.',
        ];
    }

    private function markVoicemailDealt(AutomationEvent $event, ?Model $subject): array
    {
        if (! $subject instanceof PhoneCall) {
            return [
                'type' => 'voicemail.mark_dealt',
                'ok' => false,
                'detail' => 'That record is not a phone call, so it cannot be marked dealt with.',
            ];
        }

        if (! $subject->voicemail) {
            return [
                'type' => 'voicemail.mark_dealt',
                'ok' => false,
                'detail' => 'That call has no voicemail to mark dealt with.',
            ];
        }

        if ($subject->dealt_at !== null) {
            return [
                'type' => 'voicemail.mark_dealt',
                'ok' => true,
                'detail' => 'That voicemail was already marked dealt with.',
            ];
        }

        $subject->dealt_at = now();
        $subject->save();

        $this->audit->log(
            action: 'phone.voicemail_marked_dealt_by_automation',
            target: $subject,
            changes: ['after' => ['dealt_at' => $subject->dealt_at?->toIso8601String()]],
            metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
        );

        return [
            'type' => 'voicemail.mark_dealt',
            'ok' => true,
            'detail' => 'Marked the voicemail dealt with.',
        ];
    }

    /**
     * Open a job in reach titled from the caller number. Does not place a call.
     *
     * @return array{type: string, ok: bool, detail: string}
     */
    private function createJobFromMissed(AutomationEvent $event, ?Model $subject): array
    {
        if (! $subject instanceof PhoneCall) {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'That record is not a phone call, so a job cannot be opened from it.',
            ];
        }

        $caller = (string) ($subject->from_e164 ?? '');
        if ($caller === '') {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'That call has no caller number to title a job from.',
            ];
        }

        $franchise = $this->franchiseForCall($subject);
        if ($franchise === null) {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'No franchise is in reach for that phone line, so a job cannot be opened.',
            ];
        }

        $statuses = Job::allowedStatuses($franchise);
        $status = $statuses[0] ?? null;
        if ($status === null) {
            return [
                'type' => 'missed.create_job',
                'ok' => false,
                'detail' => 'This organization has no job statuses configured.',
            ];
        }

        $job = DB::transaction(function () use ($subject, $caller, $franchise, $status, $event) {
            $customer = Customer::query()->where('phone', $caller)->first();

            if ($customer === null) {
                $customer = Customer::create([
                    'owning_franchise_id' => $franchise->id,
                    'number' => $this->numbers->next(RecordNumberGenerator::CUSTOMERS, $franchise),
                    'name' => $caller,
                    'phone' => $caller,
                ]);
            }

            $job = Job::create([
                'franchise_id' => $franchise->id,
                'customer_id' => $customer->id,
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                'title' => $caller,
                'status' => $status,
                'currency' => strtoupper($franchise->currency ?: 'USD'),
                'description' => 'Opened from a missed call on '
                    .((string) ($subject->to_e164 ?: 'a phone line')).'.',
            ]);

            $this->audit->log(
                action: 'job.created_by_automation',
                target: $job,
                metadata: [
                    'caused_by_event' => $event->getKey(),
                    'trigger' => $event->event_key,
                    'from_call' => $subject->getKey(),
                ],
            );

            $this->emitter->emit(
                'job.created',
                $job,
                ['after' => $job->only([
                    'status', 'title', 'franchise_id', 'customer_id', 'amount_minor',
                ])],
                causedBy: $event,
            );

            return $job;
        });

        return [
            'type' => 'missed.create_job',
            'ok' => true,
            'detail' => "Opened job {$job->number} titled {$caller}.",
        ];
    }

    private function franchiseForCall(PhoneCall $call): ?Franchise
    {
        $number = $call->number_id !== null
            ? PhoneNumber::query()->find($call->number_id)
            : null;

        if ($number === null) {
            return null;
        }

        return match ($number->scope_type) {
            PhoneNumber::SCOPE_FRANCHISE => Franchise::query()->find($number->scope_id),
            PhoneNumber::SCOPE_COMPANY => Franchise::query()
                ->where('company_id', $number->scope_id)
                ->orderBy('name')
                ->first(),
            PhoneNumber::SCOPE_ORGANIZATION => Franchise::query()->orderBy('name')->first(),
            default => null,
        };
    }

    /**
     * Change a field on the record that triggered the rule.
     *
     * Emits an update event of its own, so a second rule can react to it — and
     * so the cascade is counted. Writing directly to the database instead would
     * make automation invisible to automation, which is a surprising and
     * hard-to-debug asymmetry.
     */
    private function updateField(array $action, AutomationEvent $event, ?Model $subject): array
    {
        if ($subject === null) {
            return ['type' => 'update_field', 'ok' => false, 'detail' => 'The record no longer exists.'];
        }

        $field = $action['field'] ?? null;
        $value = $action['value'] ?? null;

        if ($field === null) {
            throw new InvalidArgumentException('update_field needs a field.');
        }

        $writable = self::writableOn($subject::class);

        if (! array_key_exists($field, $writable)) {
            return [
                'type' => 'update_field',
                'ok' => false,
                'detail' => "'{$field}' is not a field an automation may set on "
                    .class_basename($subject).'. Allowed: '.implode(', ', array_keys($writable)).'.',
            ];
        }

        $before = $subject->getAttribute($field);

        if ($this->same($before, $value)) {
            // Not a failure, and worth recording: a rule that keeps setting a
            // field to what it already is would otherwise emit an event every
            // time and look like a loop.
            return [
                'type' => 'update_field',
                'ok' => true,
                'detail' => "'{$field}' was already ".json_encode($value).'.',
            ];
        }

        $subject->setAttribute($field, $value);

        // Audited like any other change (§2.2). An automation altering a
        // customer's email or a job's price left nothing in the audit log at
        // all, which is the one place somebody would look to find out what
        // happened.
        $this->audit->log(
            action: strtolower(class_basename($subject)).'.updated_by_automation',
            target: $subject,
            changes: ['before' => [$field => $before], 'after' => [$field => $value]],
            metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
        );

        $subject->save();

        $payload = ['before' => [$field => $before], 'after' => [$field => $value]];

        $this->emitter->emit(
            eventKey: $this->updateEventFor($subject),
            subject: $subject,
            payload: $payload,
            causedBy: $event,
        );

        // The specific trigger too, not only the generic one.
        $secondary = self::SECONDARY_TRIGGERS[$subject::class][$field] ?? null;

        if ($secondary !== null) {
            $this->emitter->emit($secondary, $subject, $payload, causedBy: $event);
        }

        return [
            'type' => 'update_field',
            'ok' => true,
            'detail' => "{$field}: ".json_encode($before).' → '.json_encode($value),
        ];
    }

    /**
     * Move a record to a named status.
     *
     * Jobs already allow this via update_field. Vehicles go through
     * VehicleStatus::apply so a rule cannot mark a rental sold without the same
     * 422 a person saving would see.
     */
    private function changeStatus(array $action, AutomationEvent $event, ?Model $subject): array
    {
        if ($subject === null) {
            return ['type' => 'change_status', 'ok' => false, 'detail' => 'The record no longer exists.'];
        }

        $value = $action['value'] ?? $action['status'] ?? null;

        if ($value === null) {
            throw new InvalidArgumentException('change_status needs a status.');
        }

        if ($subject instanceof Job) {
            $result = $this->updateField(
                ['field' => 'status', 'value' => $value],
                $event,
                $subject,
            );
            $result['type'] = 'change_status';

            return $result;
        }

        if (! $subject instanceof Vehicle) {
            return [
                'type' => 'change_status',
                'ok' => false,
                'detail' => 'A rule cannot change the status of that record.',
            ];
        }

        $next = VehicleStatus::tryFrom((string) $value);

        if ($next === null) {
            return [
                'type' => 'change_status',
                'ok' => false,
                'detail' => "'{$value}' is not a vehicle status.",
            ];
        }

        $before = $subject->status;

        if ($before === $next) {
            return [
                'type' => 'change_status',
                'ok' => true,
                'detail' => "status was already {$next->value}.",
            ];
        }

        $buyingBack = $before->isEnded() && $next->isOnRoad();

        try {
            DB::transaction(function () use ($subject, $next, $before, $action, $event, $buyingBack) {
                VehicleStatus::apply($subject, $next, $action['ownership'] ?? null);

                $this->audit->log(
                    action: 'vehicle.updated_by_automation',
                    target: $subject,
                    changes: [
                        'before' => ['status' => $before->value],
                        'after' => ['status' => $next->value],
                    ],
                    metadata: ['caused_by_event' => $event->getKey(), 'trigger' => $event->event_key],
                );

                $subject->save();

                $payload = [
                    'old' => $before->value,
                    'new' => $next->value,
                    'before' => ['status' => $before->value],
                    'after' => ['status' => $next->value],
                ];

                $this->emitter->emit('vehicle.status_changed', $subject, $payload, causedBy: $event);

                if ($buyingBack) {
                    $period = $subject->openOwnership();
                    if ($period !== null) {
                        $this->emitter->emit('vehicle.ownership_started', $subject, [
                            'after' => [
                                'kind' => $period->kind->value,
                                'counterparty' => $period->counterparty,
                                'started_on' => $period->started_on?->toDateString(),
                            ],
                        ], causedBy: $event);
                    }
                }
            });
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?? $e->getMessage();

            return ['type' => 'change_status', 'ok' => false, 'detail' => $message];
        }

        return [
            'type' => 'change_status',
            'ok' => true,
            'detail' => 'status: '.json_encode($before->value).' → '.json_encode($next->value),
        ];
    }

    private function callWebhook(array $action, AutomationEvent $event, ?Model $subject, ?string $idempotencyKey): array
    {
        $url = $action['url'] ?? null;

        if ($url === null) {
            throw new InvalidArgumentException('webhook needs a url.');
        }

        $result = $this->webhook->send($url, [
            'event' => $event->event_key,
            'occurred_at' => $event->occurred_at->toIso8601String(),
            'subject' => [
                'type' => $subject ? class_basename($subject) : null,
                'id' => $event->subject_id,
            ],
            'payload' => $event->payload,
            // So a receiver can discard a redelivery rather than acting twice.
            'idempotency_key' => $idempotencyKey ?? $event->getKey(),
        ], $action['secret'] ?? null);

        return [
            'type' => 'webhook',
            'ok' => $result['ok'],
            'retryable' => $result['retryable'] ?? (in_array($result['status'] ?? null, [408, 425, 429], true) || ($result['status'] ?? 0) >= 500),
            'unknown' => ! $result['ok'] && ($result['status'] ?? null) === null && ($result['retryable'] ?? false),
            'detail' => $result['ok']
                ? "Delivered, HTTP {$result['status']}."
                : ($result['error'] ?? 'Failed.'),
        ];
    }

    /**
     * An in-app notification.
     *
     * The audience is whoever could have seen the record anyway — reach decides
     * it, exactly as it decides the job list (§4). Telling somebody about a
     * record they are not allowed to read is a leak dressed up as a feature: the
     * title alone usually gives the record away.
     */
    private function notify(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $message = $action['message'] ?? null;

        if ($message === null) {
            throw new InvalidArgumentException('notify needs a message.');
        }

        $title = $this->interpolate($message, $event, $subject);

        /*
         * NEGATIVE KEY, AND THAT IS NOT A STYLE CHOICE.
         *
         * An untouched switch posts a real `false`, so a positively-phrased key
         * - `send_email` - would have read as "no email" on every rule that
         * exists today and silenced all of them the moment it shipped. Unchecked
         * here means FOLLOW THE ORGANIZATION, which is what `false` already
         * means to the dispatcher.
         *
         * It only ever NARROWS. The company setting stays the ceiling: a rule
         * may decline the email, never demand one the company has switched off.
         * That direction matters more than it looks - inverted, a rule author
         * would be able to overrule a company setting from a screen the company
         * cannot see.
         */
        $inAppOnly = (bool) ($action['in_app_only'] ?? false);

        $sent = $this->notifications->dispatch(
            title: $title,
            body: $action['body'] ?? null,
            subject: $subject,
            source: 'automation',
            eventId: $event->getKey(),
            inAppOnly: $inAppOnly,
        );

        return [
            'type' => 'notify',
            'ok' => true,
            // Zero is not a failure, but it stopped meaning one thing.
            //
            // It has always meant "nobody has reach over that record" — a rule
            // scoped to a franchise nobody is assigned to. Since the in-app
            // gate moved inside dispatch() it ALSO means "this company has
            // automation notifications switched off", and those two send a
            // person debugging a rule to opposite places: one is a scoping
            // problem in the rule, the other is a settings screen the rule
            // author may not even be able to open.
            //
            // allows() is public for this. Asking it costs one settings read on
            // the zero path only, which is the path somebody is already staring
            // at because their rule looks broken.
            //
            // THE FLAG GOES TO BOTH CALLS. Ask allows() without it and a rule
            // that declined the email, in a company with the bell off and email
            // on, sends nobody a notification and then reports "nobody has reach
            // over that record" - because allows() still sees a live email
            // channel it is no longer allowed to use. The author would audit
            // PERMISSIONS over a switch they set themselves one screen away.
            // Notifications named this rather than leaving it in a diff.
            'detail' => $sent === 0
                ? ($this->notifications->allows('automation', $subject, $inAppOnly)
                    ? "Nobody has reach over that record, so there was no one to tell: {$title}"
                    : 'Automation notifications are switched off for this company, so nobody '
                        ."was told: {$title}")
                : "Told {$sent} ".($sent === 1 ? 'person' : 'people').": {$title}",
        ];
    }

    /**
     * `{{ field }}` from the subject, and nothing else.
     *
     * Not a template engine, deliberately. Tenant-authored strings evaluated as
     * code is remote code execution wearing a friendly name; this only ever
     * substitutes attribute values, and an unknown field becomes an empty string
     * rather than an error nobody can act on.
     */
    private function interpolate(string $template, AutomationEvent $event, ?Model $subject): string
    {
        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($subject) {
            $value = $subject?->getAttribute($m[1]);

            return is_scalar($value) ? (string) $value : '';
        }, $template);
    }

    /**
     * The update trigger for a model, so an action's own event routes correctly.
     */
    private function updateEventFor(Model $subject): string
    {
        return strtolower(class_basename($subject)).'.updated';
    }

    private function same(mixed $a, mixed $b): bool
    {
        if ($a instanceof \DateTimeInterface) {
            $a = $a->format(DATE_ATOM);
        }

        return (string) (is_scalar($a) || $a === null ? $a : json_encode($a))
            === (string) (is_scalar($b) || $b === null ? $b : json_encode($b));
    }
}
