<?php

namespace App\Support\Automations;

use App\Mail\InspectionReportMail;
use App\Models\Automation;
use App\Models\A1LeadsReviewEvent;
use App\Services\A1Leads\A1LeadsGbpReplies;
use App\Models\TimeClockReport;
use App\Support\TimeClock\TimeClockReports;
use App\Support\Modules\ModuleRegistry;
use App\Support\Social\WhatsAppGroups;
use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\WorkizJob;
use App\Support\Workiz\WorkizJobWriter;
use App\Models\Job;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use App\Models\Lead;
use App\Support\Reports\SendSavedReport;
use App\Models\SavedReport;
use App\Support\Leads\LeadReplies;
use App\Models\Invitation;
use App\Support\Hr\CannotActOnInvitation;
use App\Support\Hr\InvitationActions;
use App\Support\Fleet\CannotAssignVehicle;
use App\Support\Fleet\VehicleAssignments;
use App\Support\Access\AdministratorFloor;
use App\Support\Social\WhatsAppCloud;
use Illuminate\Support\Facades\Cache;
use App\Models\Company;
use App\Mail\AutomationMessage;
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
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\Contract;
use App\Models\Onboarding;
use App\Models\NewsletterUnsubscribe;
use App\Models\NewsletterAudience;
use App\Models\SocialConnection;
use App\Support\Newsletter\MailchimpClient;
use App\Support\Newsletter\NewsletterSendFailed;
use App\Support\Social\SocialProvider;
use App\Models\Role;
use App\Support\Customers\ReassignCustomerOwner;
use Illuminate\Support\Facades\Gate;
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
use RuntimeException;
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
    /**
     * Per rule, per hour. SES keeps a larger cap across the whole business.
     *
     * The arithmetic rather than a feeling: a runaway CASCADE is already bounded
     * by ACTION_BUDGET (50 actions per event) and MAX_DEPTH, so one event cannot
     * produce unbounded mail. This is for volume across many events - a rule on
     * job.status_changed for a company doing fifty jobs a day sits around two an
     * hour, so this leaves two orders of magnitude of headroom and still stops a
     * loop between two rules or a sync restating a thousand records.
     */
    private const EMAILS_PER_RULE_PER_HOUR = 100;

    /** An automatic FIRST reply only to an enquiry this fresh; older ones were imported. */
    private const FIRST_REPLY_WITHIN_MINUTES = 60;

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

        /*
         * `lead.update_field` HAS BEEN PUBLISHED AS WORKING AND HAD NOTHING TO
         * WRITE. Leads declares it and describes it — "sets one field on the
         * lead that triggered the rule: its status, or the summary kept against
         * it" — and this map had no Lead entry, so `writableOn(Lead::class)`
         * answered an empty array. The action was offered, the field picker had
         * no options, and the description promised two fields that could not be
         * chosen. Found on 2026-09-16 while answering a question about a
         * different module.
         *
         * EXACTLY THE TWO THE DESCRIPTION NAMES, and no more. The contact
         * details an enquirer gave are deliberately absent: they are a record of
         * what somebody said, and the description says a rule cannot change
         * them. `source`, `remote_id` and the source's own status belong to the
         * advertising account rather than to us.
         */
        Lead::class => [
            'status' => 'Status',
            'summary' => 'Summary',
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
                'facebook' => 'Facebook',
                'instagram' => 'Instagram',
                'local_services' => 'Google Local Services',
                'thumbtack' => 'Thumbtack',
                'website_chat' => 'Website live chat',
                'website_form' => 'Website form',
                'website_booking' => 'Website online booking',
                'whatsapp' => 'WhatsApp',
                'workiz' => 'Workiz',
                'ringcentral' => 'RingCentral call',
                'ez_calls' => 'EZ call',
                'ez_texts' => 'EZ text',
            ],
            'status' => [
                'new' => 'New',
                'booked' => 'Booked',
                'archived' => 'Archived',
                'not_a_lead' => 'Not a real enquiry',
                'completed' => 'Completed',
                'lost' => 'Lost (declined, or a service we do not offer)',
                'job_applicant' => 'Job applicant',
                'follow_up' => 'Follow up',
            ],
        ],
        // Names, not keys: since custom statuses the event carries the name,
        // and TaskWriter takes either. Custom names come from real events.
        Task::class => [
            'status' => ['Not started' => 'Not started', 'Working on it' => 'Working on it', 'Stuck' => 'Stuck', 'Done' => 'Done'],
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
        return $model === Job::class || $model === Vehicle::class || $model === Task::class;
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
        private readonly WhatsAppCloud $whatsapp,
        private readonly AdministratorFloor $administrators,
        private readonly VehicleAssignments $vehicleAssignments,
        private readonly InvitationActions $invitations,
        private readonly LeadReplies $leadReplies,
        private readonly SendSavedReport $reports,
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

        // A message whose link is missing (no site with a review link; no
        // membership to offer) is skipped, not sent with a hole (Web, 2026-10-08).
        foreach (['review_link' => 'review link', 'membership_link' => 'membership link'] as $field => $words) {
            if ($subject !== null && blank($subject->getAttribute($field))
                && preg_match('/\{\{\s*'.$field.'\s*\}\}/i', json_encode($action))) {
                return ['type' => $type, 'ok' => true, 'skipped' => true, 'detail' => "Skipped: there is no {$words} for this."];
            }
        }

        return match ($type) {
            'update_field' => $this->updateField($action, $event, $subject),
            'change_status' => $this->changeStatus($action, $event, $subject, $automation),
            'webhook' => $this->callWebhook($action, $event, $subject, $idempotencyKey),
            'notify' => $this->notify($action, $event, $subject),
            'notify_administrators' => $this->notifyAdministrators($action, $event, $subject),
            'reassign_owner' => $this->reassignOwner($action, $subject, $automation),
            'job.create' => $this->createFollowUpJob($action, $event, $subject),
            'draft_reply' => $this->draftReviewReply($action, $event, $subject),
            'onboarding.start' => $this->startOnboarding($action, $subject),
            'add_to_audience' => $this->addToAudience($action, $subject),
            'review_reply' => $this->replyOnGoogle($action, $event, $subject, $automation),
            'send_report' => $this->sendWorkHoursReport($action, $event, $automation),
            'whatsapp.group_send' => $this->sendToWhatsAppGroup($action, $event, $subject, $automation),

            // ActionDefinition::type() keeps everything after the first dot, so
            // phone.voicemail.mark_dealt arrives here as voicemail.mark_dealt.
            'voicemail.mark_dealt' => $this->markVoicemailDealt($event, $subject),
            'message.mark_dealt' => $this->markMessageDealt($event, $subject),
            'missed.create_job' => $this->createJobFromMissed($event, $subject),
            'deactivate' => $this->deactivate($subject),
            'assign_to_user' => $this->assignToUser($action, $event, $subject),
            'unassign' => $this->unassign($subject),
            'resend' => $this->resendInvitation($subject),
            'revoke' => $this->revokeInvitation($subject),
            'reply' => $this->replyToLead($action, $event, $subject),
            'report.send' => $this->sendReport($action, $event, $automation),
            'email.send' => $this->sendEmail($action, $event, $automation, $subject),
            'whatsapp.send' => $this->sendWhatsApp($action, $event, $subject),
            'sms.send' => $this->sendSms($action, $event, $automation, $subject),

            'time_clock.clock_in' => $this->punch($event, $subject, in: true),
            'time_clock.clock_out' => $this->punch($event, $subject, in: false),

            'training.assign' => $this->assignTraining($action, $event, $subject, $automation),
            'inspection_report.email' => $this->emailInspectionReport($action, $subject, $automation),
            'inspection_report.workiz' => $this->putInspectionReportOnWorkiz($subject),

            'task.create' => $this->createTask($action, $event, $subject, $automation),
            'task.assign' => $this->assignTask($action, $event, $subject, $automation),
            'task.move_group' => $this->moveTaskToGroup($action, $subject, $automation),
            'alert.send' => $this->sendAlert($action, $event, $subject, $automation),
            'report.text' => $this->reportText($action, $automation),
            'lead.text' => $this->textLead($action, $event, $subject, $automation),

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
     * beliefs — that asTenant binds a tenant to their own domain, that a
     * tenant-choosable flag on the transport prevents it, that `canSend()`
     * checks verification — were reasoned from a name or from a neighbouring
     * guard, and none of them covered the resolver landing on Platform with
     * nobody having chosen it.
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
    /**
     * The finished report, as a link on its Workiz job (Jose via Inspection,
     * 2026-10-06: "inspection reports should also go up in workiz when done
     * via the app"). No Workiz API, v1 or v2, takes a file, so the link is
     * what goes up: one line at the end of the job's description, where staff
     * look, through a link that opens without an EzBizi sign-in
     * (InspectionReport::shareUrl). Written once per report, so a retried run
     * adds nothing. An inspection on a client alone, or on a job Workiz does
     * not have, is left alone.
     */
    private function putInspectionReportOnWorkiz(?Model $subject): array
    {
        $type = 'inspection_report.workiz';

        if (! $subject instanceof InspectionReport) {
            return ['type' => $type, 'ok' => false, 'detail' => 'This action only works on a submitted inspection, so nothing was sent to Workiz.'];
        }

        $mirror = $subject->job_id === null ? null : WorkizJob::query()->where('job_id', $subject->job_id)->first();

        if ($mirror === null) {
            return ['type' => $type, 'ok' => false, 'detail' => 'This inspection is not on a Workiz job, so there was nothing to put it on.'];
        }

        $link = $subject->shareUrl();
        $result = app(WorkizJobWriter::class)->appendLine(
            $mirror,
            'Inspection report '.($subject->submitted_at ?? now())->format('m/d/Y').': '.$link,
            $link,
        );

        return match (true) {
            $result['refused'] !== null => ['type' => $type, 'ok' => false, 'detail' => 'Workiz did not take it: '.$result['refused']],
            ! $result['written'] => ['type' => $type, 'ok' => true, 'detail' => 'The report was already on the Workiz job.'],
            default => ['type' => $type, 'ok' => true, 'detail' => 'The report link was added to the Workiz job.'],
        };
    }

    private function emailInspectionReport(array $action, ?Model $subject, ?Automation $automation): array
    {
        $type = 'inspection_report.email';


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
         * EZBIZI SENDS IT, AND THE BODY SAYS WHO WROTE IT.
         *
         * This used to refuse when the resolved identity was EzBizi, on the
         * reasoning that a customer told their chimney was swept has no
         * relationship with us and should not learn which software the company
         * runs. Jose reversed that on 2026-09-16, in his own words asking for
         * automation actions on "Email ( the Ezbizi SES one, not the company
         * one )". So the condition the old guard refused on is now the
         * INTENDED one, and leaving it would have refused every send: every
         * organization resolves to Platform while sending_identities is empty.
         *
         * `platformForTenant` rather than `platform`, and the distinction is
         * the dispatcher's: platform() has no tenant lookup at all, which is
         * what keeps sign-in and password-reset mail separable. This one knows
         * the scope, so tenant-authored traffic is countable and suppressible
         * on its own rather than being indistinguishable from an invitation —
         * which matters because SES reputation is shared platform-wide and
         * nothing yet consults a bounce or complaint list.
         *
         * `asTenant` is NOT the method any more. It was retired to an
         * unconditional throw: it put a TENANT's address on our transport,
         * which is a claim about who is writing. This puts EZBIZI's address on
         * it, and `companyName` below is what makes the body say who actually
         * did the work.
         */
        if ($automation !== null && ($refusal = $this->ruleAllowanceRefusal($automation)) !== null) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal];
        }

        try {
            $this->mail->platformForTenant(
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

    /**
     * Email a saved report to the people who read it.
     *
     * ── THE ACTOR PROBLEM, AND WHAT IT TURNED OUT TO BE ──────
     *
     * `send()` takes a `User $as`, and a rule fires at 3am with nobody behind
     * it. Asking Reports which user to pass turned up something worse than the
     * question: their runner took its SCOPE from `ViewScope::forUser`, which
     * reads the chip that person last clicked in their own top bar. So a
     * scheduled report rendered at a view chosen months earlier for unrelated
     * reasons, and the same rule produced different figures depending on it.
     *
     * Fixed on their side (081ee5be): a saved report carries its own place and
     * the run uses that. The author is now only a REACH FLOOR - and if they
     * have lost reach over the report's place, or left, it refuses rather than
     * quietly rendering a smaller report. Which was the direction I had wrong:
     * I expected a demoted author to keep rendering wide, and it would have
     * SHRUNK. Nobody notices a number getting smaller.
     *
     * So the author is the rule's author, and the failure that matters is
     * caught as `AuthorizationException`.
     *
     * ── THREE REFUSALS, THREE ERRANDS ──────
     *
     *   AuthorizationException  the author cannot reach the report's place
     *   RuntimeException        the RULE's scope cannot read the report it names
     *   CannotSendMail          this business is past its hourly allowance
     *
     * Caught apart rather than as one `Throwable`, because they send somebody to
     * three different places and only the middle one is fixable in the rule.
     *
     * ── PARTIAL FAILURE IS A SUCCESS ──────
     *
     * One permanently-bad address among five must not fail the action: five
     * consecutive failures switch a rule off, so a weekly report would go dark
     * after five weeks while four people were reading it fine. `ok()` is false
     * only when NOTHING left the building.
     */
    private function sendReport(array $action, AutomationEvent $event, ?Automation $automation): array
    {
        $type = 'report.send';

        if ($automation === null) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step can only run as part of a saved rule.',
            ];
        }

        $report = SavedReport::find((string) ($action['report_id'] ?? ''));

        if ($report === null) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'The report this step names no longer exists.',
            ];
        }

        $recipients = [];

        foreach ((array) ($action['to'] ?? []) as $stored) {
            $one = $this->contactFromStored(trim((string) $stored), $event, 'email');

            if (is_string($one) && filter_var($one, FILTER_VALIDATE_EMAIL) !== false) {
                $recipients[] = $one;
            }
        }

        $recipients = array_values(array_unique($recipients));

        if ($recipients === []) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'Nobody this step names has an email address, so there was nobody to '
                    .'send the report to.',
            ];
        }

        $author = $automation->activeVersion?->created_by !== null
            ? User::find($automation->activeVersion->created_by)
            : null;

        if ($author === null) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'The person who built this rule no longer works here, so the report '
                    .'cannot be sent. Open the rule and save it again to take it over.',
            ];
        }

        try {
            $result = $this->reports->send(
                $report,
                $recipients,
                $automation,
                $author,
                // A WARNING THAT FOUND NOTHING SENDS NOTHING, when the rule says
                // so. Default false, so every digest written before today keeps
                // mailing its empty result — right for "here is the week", wrong
                // for "tell me if anything is booked out", and the rule author
                // is the only one who knows which they wrote.
                skipWhenNoRows: (bool) ($action['skip_when_empty'] ?? false),
            );
        } catch (AuthorizationException) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'The person who built this rule can no longer see everything the '
                    .'report covers, so it was not sent. Open the rule and save it again to take '
                    .'it over.',
            ];
        } catch (CannotSendMail $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        } catch (RuntimeException $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        /*
         * WHAT WAS SENT, NOT WHAT WAS ASKED FOR.
         *
         * This logged `$recipients` — the addresses the rule NAMES — before
         * reading the result, so a run where every address was refused wrote an
         * audit entry saying the report went to people nobody wrote to. An
         * audit trail is where somebody goes when they need to be certain
         * rather than approximately right, so a false entry there costs more
         * than a missing one: it cannot be told apart from a true one later.
         *
         * `$result->sent` is the addresses the transport accepted. Nothing
         * accepted, nothing logged.
         *
         * Found by the Reports session's suppression work, which adds a state
         * where a send deliberately does not happen — but the defect is older
         * than that and live today, because an all-refused send already
         * returns rather than throwing.
         */
        if ($result->sent !== []) {
            $this->audit->log(
                'automation.report_sent',
                $report,
                metadata: ['to' => $result->sent],
            );
        }

        return [
            'type' => $type,
            // Partial failure is a success. See the docblock.
            'ok' => $result->ok(),
            // THE CALLEE WRITES THIS LINE, not us. `refused` is a map of
            // address => why, so imploding it prints the reasons and drops the
            // addresses; and `transportDelivers` is false on a box whose mail
            // goes nowhere, which a count of recipients cannot say. Both are
            // already said correctly by summary(), which exists for this.
            'detail' => '"'.$report->name.'". '.$result->summary(),
        ];
    }

    /**
     * Reply in the conversation the enquiry arrived on. Website chat only.
     *
     * ── THE LIMIT IS A FILING, NOT A PREFERENCE ──────
     *
     * EzBizi told Google's ads-api-compliance team and OAuth Trust and Safety,
     * in writing, that "the only write is appendLeadConversation: one reply a
     * member of staff types and sends to a text enquiry". Both submissions are
     * open; one has bounced twice on discrepancies. A rule reaching a
     * local_services lead makes a live filing false.
     *
     * So this asks `allowsUnattendedReply()` and NOT `canReply()`. They are
     * different questions and Leads holds them apart on purpose: a Google
     * enquiry CAN be replied to - a person does it daily - and must not be
     * replied to by software. An arm testing `canReply()` would get true and
     * send. The callee refuses a true flag as well, so a caller that forgets is
     * stopped in the room where the decision is; this check is the one that
     * produces a sentence somebody can read.
     *
     * ── WHY THE REPLY IS MARKED AUTOMATIC ──────
     *
     * "Unreplied" means nobody has ANSWERED this person - `LeadThread` computes
     * `last_contact_from` from the newest row a PERSON was in. Without the flag
     * every rule anybody wrote would quietly empty the list a business uses to
     * find the enquiries nobody has answered, and the Replied badge on every row
     * would say the same untruth. The chain that carries it is three modules
     * deep and every link has a default, so it compiled perfectly while dropping
     * the flag at one call site - see docs/AUTOMATION-COVERAGE.md.
     */
    private function replyToLead(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $type = 'reply';
        $message = trim((string) ($action['message'] ?? ''));

        if ($message === '') {
            return ['type' => $type, 'ok' => false, 'detail' => 'This step needs a message.'];
        }

        if (! $subject instanceof Lead) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step can only reply to an enquiry, and this rule is about '
                    .'something else.',
            ];
        }

        /*
         * "Lead received" is also raised for an OLD enquiry seen for the first
         * time - an account connected today brings 31 days of them. A first
         * reply to somebody who wrote weeks ago is not a first reply (Jose
         * switched the auto replies on 2026-09-30). Succeeds rather than
         * fails: a failure count would switch the rule off during a backfill.
         */
        if ($event->event_key === 'lead.received'
            && $subject->received_at !== null
            && $subject->received_at->lt(now()->subMinutes(self::FIRST_REPLY_WITHIN_MINUTES))) {
            return [
                'type' => $type, 'ok' => true,
                'detail' => 'This enquiry arrived '.$subject->received_at->diffForHumans()
                    .' and was only just imported, so no first reply was sent.',
            ];
        }

        if (! $this->leadReplies->allowsUnattendedReply($subject)) {
            return [
                'type' => $type, 'ok' => false,
                // Names the source, because "this rule is wrong" and "this
                // source may not be answered automatically" send somebody to
                // different places.
                // Google left this on 2026-09-29 (Jose: "flip the block now");
                // a source with no channel that permits it still lands here.
                'detail' => "Enquiries from \"{$subject->source}\" can only be answered by a "
                    .'person, so nothing was sent.',
            ];
        }

        if (! $this->leadReplies->canReply($subject)) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => $this->leadReplies->whyNot($subject)
                    ?? 'This enquiry cannot be replied to just now.',
            ];
        }

        $text = $this->interpolate($message, $event, $subject);

        try {
            $this->leadReplies->for($subject)?->send($subject, $text, automatic: true);
        } catch (RuntimeException $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        $this->audit->log('automation.lead_replied', $subject);

        // For the WhatsApp "A1 Messages" feed (row H of REPLACING-RODRIGO-BOT).
        // causedBy carries the cascade depth, so a rule answering this cannot loop.
        $this->emitter->emit('lead.auto_replied', $subject, ['after' => [
            'source' => $subject->source,
            'kind' => $subject->kind(),
            'contact_name' => $subject->contact_name,
            'phone' => $subject->phone,
            'message' => $text,
        ]], causedBy: $event);

        return [
            'type' => $type,
            'ok' => true,
            'detail' => 'Replied in the chat. It is recorded as automatic, so the enquiry still '
                .'shows as awaiting a reply.',
        ];
    }

    /**
     * Send somebody's invitation again.
     *
     * ── THE RETURN VALUE IS A LIVE CREDENTIAL AND IS DISCARDED ──────
     *
     * `resend()` answers the RAW TOKEN. Only its hash is stored, so that string
     * exists nowhere else, and anyone holding it can accept the invitation and
     * become that person.
     *
     * Every arm in this file returns a `detail` sentence that lands in
     * `action_results` on the run row — kept three months, rendered on a screen
     * anybody with `automations.view` can open. So the natural way to write this
     * arm is `"Resent: {$url}"`, and that is a live credential in a durable log
     * with a UI. It is not a hypothetical near-miss; it is where this file's own
     * convention points. HR shouted about it across the module boundary and they
     * were right to.
     *
     * DO NOT PUT THE RETURN VALUE, OR A URL BUILT FROM IT, IN THE DETAIL LINE.
     * A rule has no use for it either way — nobody is reading the run log in
     * order to accept an invitation.
     *
     * ── AND THE `$email` PARAMETER IS NOT OFFERED AT ALL ──────
     *
     * `resend()` takes an optional address and re-points the invitation, killing
     * the previous link. A rule that could choose that address by condition is
     * an account takeover with a friendly interface, and no rule author would
     * recognise it as one while building it. Not accepted and ignored — absent,
     * so there is nothing to fill in and nothing to quietly drop.
     */
    private function resendInvitation(?Model $subject): array
    {
        $type = 'resend';

        if (! $subject instanceof Invitation) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step can only resend an invitation, and this rule is about '
                    .'something else.',
            ];
        }

        try {
            // Deliberately not assigned. See the docblock above.
            $this->invitations->resend($subject);
        } catch (CannotActOnInvitation $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        $this->audit->log('automation.invitation_resent', $subject);

        return [
            'type' => $type,
            'ok' => true,
            'detail' => 'Sent the invitation again. The previous link no longer works.',
        ];
    }

    /**
     * Withdraw an invitation that has not been accepted.
     *
     * Idempotent on HR's side — already revoked is a no-op rather than a
     * refusal — which is why a rule on `invitation.revoked` cannot re-fire this
     * into a loop.
     */
    private function revokeInvitation(?Model $subject): array
    {
        $type = 'revoke';

        if (! $subject instanceof Invitation) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step can only withdraw an invitation, and this rule is about '
                    .'something else.',
            ];
        }

        try {
            $this->invitations->revoke($subject);
        } catch (CannotActOnInvitation $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        $this->audit->log('automation.invitation_revoked', $subject);

        return ['type' => $type, 'ok' => true, 'detail' => 'Withdrew the invitation.'];
    }

    /**
     * Hand a job or a vehicle to the person the EVENT named.
     *
     * ONE ARM, TWO ACTIONS, and they are not the same underneath - which is the
     * point of routing rather than copying. A job's assignment is a column with
     * a reach rule; a vehicle's is an assignment PERIOD that has to displace the
     * open one and emit twice. Fleet owns the second and now exposes it
     * (VehicleAssignments, dab92dba), so this calls their code rather than
     * carrying a second copy of it that drifts the first time they change one.
     *
     * THE ASSIGNEE COMES FROM A FIELD OF THE EVENT, the same shape the email and
     * WhatsApp steps use. A rule hands the keys to the person the event was
     * about - the one who reported the fault, the one who booked the job - not
     * to somebody named once when the rule was written and still named a year
     * later after they left.
     *
     * BOTH REACH CHECKS ARE ASKED OF WHOEVER OWNS THEM. Fleet's lives inside
     * assign(), so this cannot forget it; a job's is `canActOnFranchise`, which
     * is the User model's own answer rather than arithmetic repeated here.
     */
    private function assignToUser(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $type = 'assign_to_user';
        $field = trim((string) ($action['user_field'] ?? ''));

        if ($field === '') {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step needs to know which field carries the person to assign.',
            ];
        }

        // A person chosen in the rule, or a field of the event carrying an id.
        $userId = str_starts_with($field, self::RECIPIENT_USER)
            ? substr($field, strlen(self::RECIPIENT_USER))
            : $this->valueFromEvent($field, $event);

        if (! is_string($userId) || trim($userId) === '') {
            return [
                'type' => $type, 'ok' => false,
                'detail' => "This event carried nothing in \"{$field}\", so there was nobody to "
                    .'assign.',
            ];
        }

        $assignee = User::find($userId);

        if ($assignee === null) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => "\"{$field}\" held ".json_encode($userId).', which is not somebody '
                    .'who works here.',
            ];
        }

        if ($subject instanceof Vehicle) {
            try {
                $this->vehicleAssignments->assign($subject, $assignee);
            } catch (CannotAssignVehicle $refusal) {
                return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
            }

            return [
                'type' => $type, 'ok' => true,
                'detail' => "Handed the keys to {$assignee->name}.",
            ];
        }

        if ($subject instanceof Job) {
            if (! $assignee->canActOnFranchise($subject->franchise)) {
                return [
                    'type' => $type, 'ok' => false,
                    'detail' => "{$assignee->name} is not assigned anywhere this job can be seen.",
                ];
            }

            $subject->forceFill(['performed_by_user_id' => $assignee->getKey()])->save();
            $this->audit->log('automation.assigned', $subject, metadata: ['to' => $assignee->getKey()]);

            return [
                'type' => $type, 'ok' => true,
                'detail' => "Gave the job to {$assignee->name}.",
            ];
        }

        return [
            'type' => $type, 'ok' => false,
            'detail' => 'This step can only assign a job or a vehicle, and this rule is about '
                .'something else.',
        ];
    }

    /**
     * Take the keys back.
     *
     * `release()` answers NULL when nobody held them rather than throwing, and
     * Fleet is right that null is what this arm wants: a rule unassigning a van
     * nobody drives has got what it asked for. A person who pressed Unassign
     * wants telling, which is why their controller turns the same null into a
     * 422 and this does not.
     */
    private function unassign(?Model $subject): array
    {
        $type = 'unassign';

        if (! $subject instanceof Vehicle) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step can only unassign a vehicle, and this rule is about '
                    .'something else.',
            ];
        }

        $released = $this->vehicleAssignments->release($subject);

        $this->audit->log('automation.unassigned', $subject);

        return [
            'type' => $type,
            'ok' => true,
            'detail' => $released === null
                ? 'Nobody held the keys, so nothing changed.'
                : 'Took the keys back.',
        ];
    }

    /**
     * Switch off whatever the rule was about — a company, franchise, user or customer.
     *
     * ONE ARM, FOUR ACTIONS. `ActionDefinition::type()` derives the execution
     * type from everything after the first dot, so `company.deactivate` and
     * `customer.deactivate` both arrive here as `deactivate` and the subject is
     * what differs. That is the registry working as designed — `update_field`
     * has served four modules the same way since it was written — and it is why
     * this takes the SUBJECT rather than a model name from the action.
     *
     * ── The refusals, and why they are refusals rather than guards ──────
     *
     * ALREADY OFF is not a failure. A rule that fires twice on the same record
     * should say "it was already off" and carry on, not red the run and count
     * towards the five that auto-disable it.
     *
     * THE LAST ADMINISTRATOR is refused through `AdministratorFloor`, the same
     * object the staff screen and account deletion ask, rather than a second
     * count written here. The floor's own docblock says deactivating and
     * demoting share one method so the two cannot drift; a third caller with its
     * own arithmetic would be exactly that drift. Nobody being able to undo it -
     * including the person it was done to - is worse from a rule than from a
     * screen, because no one was standing there when it happened.
     */
    private function deactivate(?Model $subject): array
    {
        $type = 'deactivate';

        if (! $subject instanceof Company
            && ! $subject instanceof Franchise
            && ! $subject instanceof User
            && ! $subject instanceof Customer) {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step can only switch off a company, a franchise, a person or '
                    .'a customer, and this rule is about something else.',
            ];
        }

        if ((bool) $subject->getAttribute('is_active') === false) {
            return [
                'type' => $type, 'ok' => true,
                'detail' => 'It was already switched off, so nothing changed.',
            ];
        }

        if ($subject instanceof User) {
            try {
                $this->administrators->assertNotTheLast($subject, 'is_active');
            } catch (ValidationException $refusal) {
                return [
                    'type' => $type, 'ok' => false,
                    'detail' => collect($refusal->errors())->flatten()->first()
                        ?? $refusal->getMessage(),
                ];
            }
        }

        $subject->forceFill(['is_active' => false])->save();

        $this->audit->log('automation.deactivated', $subject);

        return [
            'type' => $type,
            'ok' => true,
            'detail' => 'Switched off '.class_basename($subject).' '
                .json_encode((string) ($subject->getAttribute('name') ?? $subject->getKey())).'.',
        ];
    }

    /**
     * EzBizi's WhatsApp number, sending an APPROVED TEMPLATE.
     *
     * Not free text, and the constraint is Meta's rather than ours: free text is
     * accepted only inside the 24-hour window after the customer wrote to us,
     * and an automation is by definition a message the business starts. A
     * message box would have worked whenever somebody tested it just after a
     * reply and failed silently for every real use.
     *
     * ── What a throw does and does not mean ──────
     *
     * `WhatsAppCloud::send()` refuses for three different reasons and its
     * messages are written to be shown, so they are rendered VERBATIM rather
     * than wrapped. That matters for one of the three: when Meta is unreachable
     * the row is left at `queued` with no wamid - deliberately, the outbox shape
     * - so the message may still be owed. This does NOT add a sentence claiming
     * nothing was sent, because for that case it would be false. Their words
     * say what happened; mine would be a guess about which branch threw.
     */
    private function sendWhatsApp(array $action, AutomationEvent $event, ?Model $subject = null): array
    {
        $type = 'whatsapp.send';
        $template = trim((string) ($action['template'] ?? ''));
        $field = trim((string) ($action['to_field'] ?? ''));

        if ($template === '' || $field === '') {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step needs a template and a field to send to.',
            ];
        }

        // "The customer it is about" on an ENQUIRY is the enquiry itself: sent
        // through LeadWhatsApp, so the message and their answer are on its
        // thread (Meta, 2026-10-13; booking confirmations). No phone is skipped.
        if ($field === self::RECIPIENT_CUSTOMER && $subject instanceof Lead) {
            if (Lead::e164($subject->phone) === null) {
                return ['type' => $type, 'ok' => true, 'skipped' => true, 'detail' => 'The enquiry has no phone number, so no WhatsApp was sent.'];
            }

            try {
                app(\App\Support\Social\LeadWhatsApp::class)->sendTemplate($subject, $template, array_map(
                    fn ($value) => $this->interpolate((string) $value, $event, $subject),
                    array_values((array) ($action['parameters'] ?? [])),
                ), automatic: true, from: ($action['from'] ?? '') !== '' ? (string) $action['from'] : null);
            } catch (RuntimeException $refusal) {
                return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
            }

            return ['type' => $type, 'ok' => true, 'detail' => "Handed the \"{$template}\" template to WhatsApp for the customer; it is on the enquiry."];
        }

        $to = $this->contactFromStored($field, $event, 'phone', $subject);

        if (! is_string($to) || trim($to) === '') {
            return [
                'type' => $type, 'ok' => false,
                'detail' => $field === self::RECIPIENT_CUSTOMER
                    ? $this->noCustomerContact('phone number')
                    : (str_starts_with($field, self::RECIPIENT_USER)
                    ? 'The person this step sends to no longer works here, or has no phone '
                        .'number, so there was nobody to send to.'
                    : "This event carried nothing in \"{$field}\", so there was no number to "
                        .'send to.'),
            ];
        }

        // Passed as written. Their `send()` normalises "(305) 724-2199" and
        // "+1 305 724 2199" alike, and refuses what it cannot be sure of rather
        // than guessing - so cleaning it here would only give it worse input.
        $parameters = array_map(
            fn ($value) => $this->interpolate((string) $value, $event, $subject),
            array_values((array) ($action['parameters'] ?? [])),
        );

        try {
            $row = $this->whatsapp->send(
                $template,
                $to,
                $parameters,
                (string) ($action['language'] ?? 'en_US'),
                // WHICH NUMBER, because a template is approved per WABA and a
                // business can hold numbers on more than one. Meta's send()
                // refuses rather than guessing when it is not named, which is
                // right and is why the rule has to carry it.
                ($action['from'] ?? null) !== null && $action['from'] !== ''
                    ? (string) $action['from']
                    : null,
            );
        } catch (RuntimeException $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        $this->audit->log('automation.whatsapp_sent', $event, metadata: [
            'template' => $template,
            'to' => $row->counterparty,
        ]);

        return [
            'type' => $type,
            'ok' => true,
            // The NUMBER a customer sees is EzBizi's, not this business's, so the
            // line does not say "your WhatsApp". Said plainly because a rule
            // author reading it needs to know which number replies come back to.
            //
            // AND IT STOPS AT THE HANDOFF. `$row->status` is `sent` here and the
            // webhook may flip it to `failed` seconds later — Meta returns 200
            // and a wamid for a message it will refuse, measured at dba98c6c. A
            // run-log line is written once and never revisited, so printing a
            // status that expires would leave a green line above a message that
            // never arrived. The row keeps the outcome; this sentence does not
            // claim one.
            'detail' => "Handed the \"{$template}\" template to WhatsApp for "
                .($field === self::RECIPIENT_CUSTOMER ? 'the customer' : $row->counterparty).', '
                ."from EzBizi's number. WhatsApp accepts or refuses it afterwards.",
            // THE LINK, so a reader can show the outcome without this row ever
            // being rewritten: the sentence above is a true-forever snapshot of
            // the handoff, and `whatsapp_messages` carries the live status the
            // webhook maintains. Stored only for WhatsApp, because it is the
            // only send whose outcome changes AFTER the action returns — EzBizi
            // ingests no SES events, so an email arm has nothing to point at.
            // Cheap now and unrecoverable later: a run recorded without it can
            // never be joined to its message.
            'message_id' => $row->id,
        ];
    }

    /**
     * EzBizi's own mail, carrying words a tenant wrote, to somebody the EVENT named.
     *
     * ── Why the recipient is a field and not an address ──────
     *
     * `sms.send` takes a typed destination, and it is the one action anybody
     * would have copied to build this. On SMS that is fine: the line and the
     * reputation are the tenant's own Twilio number and their own A2P
     * registration, so texting whoever they like is their consequence on their
     * asset. Here the transport and the sending identity are EzBizi's, shared by
     * every tenant, and a rule that can email an arbitrary address is a spam
     * cannon with the platform's reputation behind it - one business's complaint
     * rate suppresses sign-in and password-reset delivery for all of them.
     *
     * So the author picks WHICH FIELD OF THE EVENT carries the address, from the
     * same list the condition builder offers. A rule can write to the customer
     * the event is about. It cannot write to somebody named in the rule.
     *
     * ── Two caps, doing two different jobs ──────
     *
     * This one is PER RULE and its whole point is the sentence it writes: a rule
     * that quietly half-sent for an hour is the failure this module has spent
     * the week removing. SES keeps a second, larger cap across the whole
     * business, because ten rules just under this one are still a thousand
     * messages. Theirs is the backstop; this one is what somebody reads.
     *
     * Counted BEFORE the attempt and never given back. A message that reached
     * SES and bounced has already spent the reputation the cap protects, so a
     * rule failing every time must still run out.
     */
    private function sendEmail(array $action, AutomationEvent $event, ?Automation $automation, ?Model $record = null): array
    {
        $type = 'email.send';
        $field = trim((string) ($action['to_field'] ?? ''));
        $subject = trim((string) ($action['subject'] ?? ''));

        if ($field === '' || $subject === '' || trim((string) ($action['body'] ?? '')) === '') {
            return [
                'type' => $type, 'ok' => false,
                'detail' => 'This step needs a field to send to, a subject and a message.',
            ];
        }

        $to = $this->emailRecipient($field, $event, $record);

        if (! is_string($to) || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return [
                'type' => $type, 'ok' => false,
                // Names the field, because "no address" and "that field held
                // something that is not an address" send somebody to two
                // different places - the rule, or the record.
                'detail' => $to === null || $to === ''
                    ? ($field === self::RECIPIENT_CUSTOMER ? $this->noCustomerContact('email address') : (str_starts_with($field, self::RECIPIENT_USER)
                        ? 'The person this step sends to no longer works here, or has no email '
                            .'address, so there was nobody to send to.'
                        : "This event carried nothing in \"{$field}\", so there was no address to send to."))
                    : "\"{$field}\" held ".json_encode($to).', which is not an email address.',
            ];
        }

        if ($automation !== null && ($refusal = $this->ruleAllowanceRefusal($automation)) !== null) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal];
        }

        try {
            $this->mail->platformForTenant(
                $this->scopeModelFor($automation) ?? $event->organization()->withoutGlobalScopes()->firstOrFail(),
                new AutomationMessage(
                    // $record, not $subject: in THIS arm `$subject` is the email's
                    // subject line. The record the rule fired on is the other one.
                    subjectLine: $this->interpolate($subject, $event, $record),
                    body: $this->interpolate((string) $action['body'], $event, $record,
                        forMarkdown: AutomationMessage::RENDERS_MARKDOWN),
                    businessName: (string) ($event->organization()->withoutGlobalScopes()->value('name') ?? 'this business'),
                    // EzBizi's band only when EzBizi composed the substance: a
                    // trigger that declares composedFields. Never widened to
                    // "any trigger", or a stranger's words would wear our band.
                    kind: ($trigger = app(ModuleRegistry::class)->findTrigger($event->event_key)) !== null
                        && $trigger->composedFields !== [] ? $trigger->label : null,
                    automationName: $automation?->name,
                ),
                $to,
            );
        } catch (CannotSendMail $refusal) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
        }

        // THE CUSTOMER'S ADDRESS IS NOT WRITTEN DOWN HERE. The run log and this
        // audit row hang off a job event, which a customer's erasure does not
        // reach, so their address would outlive them. "the customer" is enough
        // to read the log by; the message itself is the record of where it went.
        $shown = $field === self::RECIPIENT_CUSTOMER ? 'the customer' : $to;

        $this->audit->log('automation.emailed', $event, metadata: ['to' => $shown]);

        return ['type' => $type, 'ok' => true, 'detail' => "Emailed {$shown}: {$subject}"];
    }

    /** Prefix marking a stored recipient as a person rather than a field or an address. */
    private const RECIPIENT_USER = 'user:';

    /** Whoever a task is given to, read when the rule runs (Jose, 2026-10-13). */
    private const RECIPIENT_ASSIGNEE = 'assignee:';

    /**
     * The customer the rule is about — the job's, the report's, or the
     * customer itself. Jose, 2026-09-24: "when a job changes status to Done,
     * then it would send a message via txt or whatsapp".
     *
     * Resolved NOW, through the subject's customer, and never copied into the
     * event: the purge finds an event about a customer by its subject or by the
     * number in the keys AutomationEvent::identifiers lists, and a job's event
     * carrying the customer's number would match neither. This is one
     * named relation the author picks, not the subject fall-through
     * valueFromEvent refuses.
     */
    private const RECIPIENT_CUSTOMER = 'customer:';

    private function noCustomerContact(string $what): string
    {
        return "This is not about a customer, or the customer has no {$what}, so nothing was sent.";
    }

    /**
     * Who the email goes to: a person here, a field of the event, or an address.
     *
     * ── Three sources, one stored string, and the order is deliberate ──────
     *
     *   "user:<id>"        somebody who works here. Stored as an ID rather than
     *                      their address, so the rule follows them when they
     *                      change it and refuses when they leave. An address
     *                      copied at build time would quietly keep mailing a
     *                      mailbox nobody reads.
     *   a field name       the address the EVENT carried - the customer who
     *                      enquired, the person who wrote in.
     *   anything else      a literal address somebody typed.
     *
     * ── Why a typed address is allowed at all ──────
     *
     * It was deliberately absent at first. This sends on EzBizi's own transport
     * and sending identity, shared by every tenant, so a rule that could email
     * anywhere is a spam cannon with the platform's reputation behind it - one
     * business's complaint rate suppresses sign-in and password-reset delivery
     * for all of them. Jose asked for it directly on 2026-09-16: "it should let
     * me select users ( since they have emails attached to them ) or also let me
     * type an email".
     *
     * What holds the risk instead: 100 sends per rule per hour here, 500 per
     * business per hour in MailDispatcher, both refusing with a line in the run
     * log rather than dropping silently. Those are volume limits and NOT
     * reputation monitoring - there is still no bounce route and no complaint
     * table, which is the thing that would actually see a tenant going wrong.
     * That gap is with Jose and is the reason this docblock names it.
     */
    private function emailRecipient(string $stored, AutomationEvent $event, ?Model $subject = null): mixed
    {
        return $this->contactFromStored($stored, $event, 'email', $subject);
    }

    /**
     * The same three sources, for whichever contact detail the step needs.
     *
     * A step that texts and a step that emails ask the same question - who -
     * and differ only in which column answers it. Two resolvers would be two
     * places to fix the day a fourth source is added.
     */
    private function contactFromStored(string $stored, AutomationEvent $event, string $column, ?Model $subject = null): mixed
    {
        if ($stored === self::RECIPIENT_CUSTOMER) {
            $customer = $subject instanceof Customer
                ? $subject
                : (($id = $subject?->getAttribute('customer_id')) ? Customer::query()->find($id) : null);

            return $customer?->getAttribute($column);
        }

        if ($stored === self::RECIPIENT_ASSIGNEE) {
            return $this->assigneeOf($event, $subject)?->getAttribute($column);
        }

        if (str_starts_with($stored, self::RECIPIENT_USER)) {
            $user = User::query()
                ->where('is_active', true)
                ->find(substr($stored, strlen(self::RECIPIENT_USER)));

            return $user?->getAttribute($column);
        }

        // A literal value is stored as itself. Checked before the event, because
        // a payload key never looks like an address or a phone number and
        // neither ever looks like a payload key - so the test cannot be
        // ambiguous. A number is anything with a digit and no letters; a field
        // name has letters and no leading +.
        if ($column === 'email') {
            if (filter_var($stored, FILTER_VALIDATE_EMAIL) !== false) {
                return $stored;
            }
        } elseif (preg_match('/^[+0-9][0-9 ()\-.]*$/', $stored) === 1) {
            return $stored;
        }

        return $this->valueFromEvent($stored, $event);
    }

    /**
     * The field's value from the EVENT only - never from the subject model.
     *
     * {@see ConditionEvaluator::valueOf} falls through to the subject when the
     * payload does not carry a field, which is right for a condition: the rule
     * is asking about a record. It is wrong HERE. Falling through would let a
     * rule address mail to whatever attribute of the subject happened to share a
     * name, which is an address the event never mentioned - exactly the reach
     * this action exists to refuse.
     */
    private function valueFromEvent(string $field, AutomationEvent $event): mixed
    {
        $payload = $event->payload ?? [];

        return $payload['after'][$field] ?? $payload[$field] ?? null;
    }

    /**
     * Why this rule may not send another email, or null if it may.
     *
     * ── A COUNTER THAT IS NOT COUNTING MUST NOT READ AS PERMISSION ──────
     *
     * `Cache::increment()` returns int|false, and `false <= 100` is TRUE in
     * PHP — bool wins the comparison and the int is cast, so a counter that
     * stopped working would have read as "well within the allowance" and let a
     * rule send without limit. Fail-open on the one guard standing between a
     * loop between two rules and EzBizi's own SES reputation, which is shared
     * by every tenant and is not something a later fix gives back.
     *
     * So a non-int refuses, and says something DIFFERENT from the allowance
     * sentence. Both stop the send; only one of them is a fact about this rule,
     * and telling somebody they have sent a hundred emails when the cache is
     * broken sends them to read a run log looking for ninety-nine sends that
     * never happened.
     *
     * The test harness cannot reach this: `CACHE_STORE=array` in phpunit.xml
     * always increments. Production is `database`, measured on the box.
     */
    private function ruleAllowanceRefusal(Automation $automation): ?string
    {
        $key = 'automation-email:'.$automation->getKey().':'.now()->format('YmdH');

        // add() is the atomic one: two workers draining the outbox at once would
        // both read 0 and both write 1 with get()/put().
        //
        // AND IT IS WHAT MAKES THE COUNTER EXIST. `DatabaseStore::increment`
        // returns FALSE for a key that is not there, and production runs
        // CACHE_STORE=database. A limiter that increments without seeding first
        // never counts anything at all — measured on a fresh key:
        //
        //     array      1, 2, 3
        //     database   false, false, false
        //
        // So this line is not only about two workers racing. Removing it does
        // not make the limiter slightly wrong; it makes it stop counting, and
        // the refusal below would then be the only thing standing there.
        Cache::add($key, 0, now()->addHours(2));

        $sent = Cache::increment($key);

        if (! is_int($sent)) {
            return 'The counter that keeps this rule from sending too many emails is not '
                .'answering, so nothing was sent. Nobody has been emailed twice; try the rule '
                .'again, and tell somebody if it keeps saying this.';
        }

        return $sent <= self::EMAILS_PER_RULE_PER_HOUR
            ? null
            : 'This rule has sent '.self::EMAILS_PER_RULE_PER_HOUR.' emails in the last hour, '
                .'which is its limit. It will send again on the hour.';
    }

    /** The Organization, Company or Franchise the rule belongs to. */
    private function scopeModelFor(?Automation $automation): ?Model
    {
        if ($automation === null) {
            return null;
        }

        [$type, $id] = $automation->automationScope();

        return match ($type) {
            'company' => Company::find($id),
            'franchise' => Franchise::find($id),
            default => $automation->organization()->withoutGlobalScopes()->first(),
        };
    }

    private function sendSms(array $action, AutomationEvent $event, ?Automation $automation, ?Model $subject = null): array
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

        // Resolved the same three ways as the email step: a person here, a
        // field of the event, or a number typed in. It was typed-only, which
        // asked somebody to keep a phone number correct inside a rule for
        // years.
        $to = trim((string) $this->contactFromStored(
            trim((string) ($action['to'] ?? '')), $event, 'phone', $subject,
        ));
        // INTERPOLATED LIKE EVERY OTHER MESSAGE. This arm was the last one
        // sending {{...}} out as braces: a missed-call text that cannot name the
        // number that called is the rule Jose has been running for two weeks.
        // Emptiness is judged AFTER substitution, because a body that is nothing
        // but an unresolved field is an empty text message, not a message.
        $body = trim($this->interpolate((string) ($action['body'] ?? ''), $event, $subject));

        if ($to === '' || $body === '') {
            return [
                'type' => $type,
                'ok' => false,
                'detail' => $to === ''
                    ? ($action['to'] ?? '') === self::RECIPIENT_CUSTOMER
                        ? $this->noCustomerContact('phone number')
                        : 'No number to send to, so nothing was sent.'
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
            $sent = $this->twilio->sendMessage($connection, (string) $number->e164, $to, $body, $number->account_sid);
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
            // Not the customer's number, for the reason given in sendEmail.
            'detail' => 'Sent a message from '.$number->e164.' to '
                .(($action['to'] ?? '') === self::RECIPIENT_CUSTOMER ? 'the customer' : $to).'.',
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
            // The builder stores ONE choice as `guide:<id>` or `test:<id>`;
            // the pair is still read so a hand-written rule keeps working.
            'guide_id' => $action['guide_id']
                ?? (str_starts_with((string) ($action['training'] ?? ''), 'guide:')
                    ? substr((string) $action['training'], 6) : null),
            'test_id' => $action['test_id']
                ?? (str_starts_with((string) ($action['training'] ?? ''), 'test:')
                    ? substr((string) $action['training'], 5) : null),
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
    private function changeStatus(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation = null): array
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

        if ($subject instanceof Task) {
            // A status NAME, resolved on the task's own board (Task Board v2,
            // custom statuses): TaskWriter refuses one that board does not have.
            return $this->onTaskBoard('change_status', $automation, $subject->board, function (User $actor) use ($subject, $value) {
                app(TaskWriter::class)->update($subject, ['status' => (string) $value], $actor);

                return 'Moved the task to '.(Task::STATUSES[(string) $value] ?? (string) $value).'.';
            });
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
    /**
     * Post a free-text message to a staff WhatsApp group (Jose, 2026-10-06:
     * EzBizi replaces the Rodrigo bot's alerts with rules). WhatsAppGroups
     * delivers to the real group or, failing that, to its members one by one,
     * and catches each member's refusal itself.
     *
     * Reaching nobody is a failure: no such group, or nobody in it with a
     * phone, is something to fix, not a message that went out.
     */
    private function sendToWhatsAppGroup(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        $type = 'whatsapp.group_send';
        $purpose = trim((string) ($action['group'] ?? ''));
        $body = trim($this->interpolate((string) ($action['message'] ?? ''), $event, $subject));

        if ($purpose === '' || $body === '') {
            return ['type' => $type, 'ok' => false, 'detail' => 'This step needs a group and a message.'];
        }

        // Meta's limit for one message.
        $body = mb_substr($body, 0, 4096);
        $title = mb_substr((string) ($automation?->name ?? 'EzBizi alert'), 0, 60);

        $reached = app(WhatsAppGroups::class)->deliver($purpose, $title, $body);

        return $reached > 0
            ? ['type' => $type, 'ok' => true, 'detail' => $reached === 1 ? 'Sent to the WhatsApp group.' : "Sent to {$reached} people in the group."]
            : ['type' => $type, 'ok' => false,
                'detail' => 'Nobody was reached: the WhatsApp group "'.$purpose.'" does not exist, or nobody in it has a phone.'];
    }

    /**
     * Email a Work Hours report (Jose, 2026-09-28: the week's hours every
     * Sunday 8 PM Miami time to the dispatch manager).
     *
     * The author is the actor: rows are bounded by what THEY may see, so a
     * rule never widens anybody's reach. No hours is still a report and still
     * ok; only nobody-to-send-to and the sending allowance fail.
     */
    private function sendWorkHoursReport(array $action, AutomationEvent $event, ?Automation $automation): array
    {
        $type = 'send_report';

        if ($automation === null) {
            return ['type' => $type, 'ok' => false, 'detail' => 'This step can only run as part of a saved rule.'];
        }

        $report = TimeClockReport::find((string) ($action['report_id'] ?? ''));

        if ($report === null) {
            return ['type' => $type, 'ok' => false, 'detail' => 'The work hours report this step names no longer exists.'];
        }

        $author = $automation->activeVersion?->created_by !== null
            ? User::query()->where('is_active', true)->find($automation->activeVersion->created_by)
            : null;

        if ($author === null) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'The person who built this rule no longer works here, so the report was not '
                    .'sent. Open the rule and save it again to take it over.'];
        }

        if (($refusal = $this->ruleAllowanceRefusal($automation)) !== null) {
            return ['type' => $type, 'ok' => false, 'detail' => $refusal];
        }

        $to = [];
        foreach ((array) ($action['to'] ?? []) as $stored) {
            $one = $this->contactFromStored(trim((string) $stored), $event, 'email');
            if (is_string($one) && filter_var($one, FILTER_VALIDATE_EMAIL) !== false) {
                $to[] = $one;
            }
        }

        try {
            $sent = app(TimeClockReports::class)->send(
                $report,
                $author,
                filter_var($action['this_week'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 'current' : 'last',
                $to === [] ? null : array_values(array_unique($to)),
                fromAutomation: true,
            );
        } catch (CannotSendMail|InvalidArgumentException $refused) {
            return ['type' => $type, 'ok' => false, 'detail' => $refused->getMessage()];
        }

        return ['type' => $type, 'ok' => true,
            'detail' => 'Sent "'.$report->name.'" for '.$sent['range'].' to '.implode(', ', $sent['to']).'.'];
    }

    /**
     * Post a public reply under a new A-1 Chimney Google review.
     *
     * Jose, 2026-09-25: "this will be set using EzBizi Automations that Bar
     * himself will set up for the reply." Through A1LeadsGbpReplies::publish,
     * the same path as the Reply button, so A1 Leads stays the only writer.
     *
     * THE REVIEW COMES FROM THE SUBJECT ROW, never the payload: the row is
     * what the import wrote, and nothing a rule author types can point this
     * at a different review.
     *
     * THE AUTHOR MUST BE ABLE TO DO IT BY HAND: hold `a1leads_gbp.reply` and
     * reach the review's company. A rule never widens what its author may do.
     *
     * ALREADY ANSWERED IS NOT A FAILURE: it is the normal outcome when A1
     * Leads' own worker or somebody at the desk got there first, and counting
     * it would switch the rule off within five reviews.
     *
     * AN UNKNOWN OUTCOME IS SAID AS ONE. A dropped connection mid-post may
     * have published; the engine never re-runs a non-webhook step, and the
     * sentence tells a person to look on Google rather than run it again.
     */
    private function replyOnGoogle(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        $type = 'review_reply';

        if (! $subject instanceof A1LeadsReviewEvent) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'This step only works on a new Google review, and this rule is about something else.'];
        }

        $author = $automation?->activeVersion?->created_by !== null
            ? User::query()->find($automation->activeVersion->created_by)
            : null;

        if ($author === null || ! $author->hasPermission('a1leads_gbp.reply')
            || ! $author->reachableCompanies()->contains(fn ($c) => $c->getKey() === $subject->company_id)) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'The person who built this rule may not reply to these reviews, so nothing was '
                    .'posted. Somebody who can should open the rule and save it again.'];
        }

        $text = trim($this->interpolate((string) ($action['message'] ?? ''), $event, $subject));

        if ($text === '') {
            return ['type' => $type, 'ok' => false, 'detail' => 'The reply was empty, so nothing was posted.'];
        }

        $result = app(A1LeadsGbpReplies::class)->publish(
            (string) $subject->google_review_name, $text, null, null, $automation,
        );

        if ($result['status'] >= 200 && $result['status'] < 300 && ($result['body']['data']['ok'] ?? false) === true) {
            return ['type' => $type, 'ok' => true, 'detail' => 'Replied on Google.'];
        }

        $code = (string) ($result['body']['error']['code'] ?? '');
        $said = (string) ($result['body']['error']['message'] ?? 'A1 Leads refused the reply.');

        return match ($code) {
            'already_answered' => ['type' => $type, 'ok' => true,
                'detail' => 'The review already had a reply, so nothing was posted.'],
            'reply_outcome_unknown' => ['type' => $type, 'ok' => false, 'unknown' => true,
                'detail' => 'The connection dropped while posting, so the reply may or may not be on '
                    .'Google. Check the review there. Do not run this again.'],
            default => ['type' => $type, 'ok' => false, 'detail' => $said],
        };
    }

    /**
     * Add somebody who enquired to a Mailchimp list — only if they agreed.
     *
     * Jose, 2026-09-17: "no, needs opt-in checkbox". Somebody who sent an
     * enquiry asked for an answer, not a newsletter. So the lead has to carry
     * `marketing_consent_at`, which is written only when the website's box was
     * ticked AND the words beside it were sent, and this step reads nothing
     * else: not the email address being present, not the source, not a guess.
     *
     * NOT AGREEING IS NOT A FAILURE. A rule on "lead received" meets far more
     * people who did not tick the box than people who did. Counting each of
     * them as a failure would switch the rule off within five enquiries — so
     * it succeeds and says, plainly, that they were not added.
     *
     * A MAILCHIMP LIST ONLY. A "customers" audience holds no members to add
     * to: it is every customer in the book, picked when a newsletter is sent.
     *
     * AN EARLIER UNSUBSCRIBE WINS. `syncMembers` uses Mailchimp's
     * `status_if_new`, so somebody who left the list before stays off it even
     * though they ticked a box today. Reported, not overridden.
     */
    private function addToAudience(array $action, ?Model $subject): array
    {
        $type = 'add_to_audience';

        if (! $subject instanceof Lead) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'This step only works on an enquiry, and this rule is about something else.'];
        }

        if ($subject->marketing_consent_at === null) {
            return ['type' => $type, 'ok' => true,
                'detail' => 'They did not agree to be sent marketing email, so they were not added.'];
        }

        $email = trim((string) $subject->email);

        if ($email === '') {
            return ['type' => $type, 'ok' => true,
                'detail' => 'They agreed to marketing email but gave no address, so there was nothing to add.'];
        }

        $audience = NewsletterAudience::query()->find((string) ($action['audience_id'] ?? ''));

        if ($audience === null) {
            return ['type' => $type, 'ok' => false, 'detail' => 'The mailing list this step names no longer exists.'];
        }

        if (! $audience->isMailchimp() || ! is_string($audience->mailchimp_list_id) || $audience->mailchimp_list_id === '') {
            return ['type' => $type, 'ok' => false,
                'detail' => '"'.$audience->name.'" is your customer book, which already includes every '
                    .'customer. This step adds people to a Mailchimp list.'];
        }

        // EZBIZI'S OWN UNSUBSCRIBE WINS TOO, not only Mailchimp's. It is kept
        // per scope and outlives the list, so a rebuilt list does not bring
        // back somebody who used EzBizi's unsubscribe link.
        $left = NewsletterUnsubscribe::query()
            ->where('scope_type', $audience->scope_type)
            ->where('scope_id', $audience->scope_id)
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->exists();

        if ($left) {
            return ['type' => $type, 'ok' => true,
                'detail' => 'They unsubscribed from this business before, so they were not added.'];
        }

        $connection = $this->mailchimpConnectionFor($audience);
        $credentials = $connection?->getAttribute('credentials');
        $key = is_array($credentials) ? (string) ($credentials['api_key'] ?? '') : '';

        if ($key === '') {
            return ['type' => $type, 'ok' => false,
                'detail' => 'No Mailchimp account is connected for that list, so nobody could be added.'];
        }

        try {
            $accepted = app(MailchimpClient::class)->syncMembers($key, $audience->mailchimp_list_id, [
                array_filter([
                    'email' => $email,
                    'first_name' => trim((string) $subject->contact_name),
                ], fn ($v) => $v !== ''),
            ]);
        } catch (NewsletterSendFailed $refused) {
            return ['type' => $type, 'ok' => false, 'detail' => $refused->getMessage()];
        }

        if ($accepted === 0) {
            return ['type' => $type, 'ok' => true,
                'detail' => 'Mailchimp kept them off "'.$audience->name.'": they had unsubscribed or been removed before.'];
        }

        // THE PROOF, next to the act. What they agreed to and when is what a
        // business is asked for when somebody says "I never signed up".
        $this->audit->log('newsletter.member_added', $subject, metadata: [
            'audience' => $audience->name,
            'consented_at' => $subject->marketing_consent_at?->toIso8601String(),
            'consent_text' => $subject->marketing_consent_text,
        ]);

        return ['type' => $type, 'ok' => true, 'detail' => 'Added them to "'.$audience->name.'".'];
    }

    /**
     * The Mailchimp account a list lives in, following ownership: the list's
     * own place, then its company and that company's parent, then the
     * organization — and STOP. Never "any connection": that would be another
     * business's key. None on the chain is "no account connected".
     */
    private function mailchimpConnectionFor(NewsletterAudience $audience): ?SocialConnection
    {
        $chain = [[$audience->scope_type, $audience->scope_id]];

        $company = match ($audience->scope_type) {
            SocialConnection::SCOPE_FRANCHISE => Franchise::query()->whereKey($audience->scope_id)->value('company_id'),
            SocialConnection::SCOPE_COMPANY => $audience->scope_id,
            default => null,
        };

        if ($company !== null) {
            // ponytail: one hop up is the whole tree — a Sub Company cannot hold
            // Sub Companies (Company.php), so there is no deeper parent to walk.
            $parent = Company::query()->whereKey($company)->value('parent_company_id');
            $chain[] = [SocialConnection::SCOPE_COMPANY, $company];
            $chain[] = [SocialConnection::SCOPE_COMPANY, $parent];
        }

        $candidates = SocialConnection::query()
            ->where('provider', SocialProvider::Mailchimp->value)
            ->get();

        foreach ($chain as [$scopeType, $scopeId]) {
            $match = $candidates->first(fn (SocialConnection $c) => $scopeId !== null
                && $c->scope_type === $scopeType && $c->scope_id === $scopeId);

            if ($match !== null) {
                return $match;
            }
        }

        return $candidates->first(fn (SocialConnection $c) => $c->scope_type === SocialConnection::SCOPE_ORGANIZATION);
    }

    /**
     * Open the paperwork for somebody who has been invited.
     *
     * Human Resources built `InvitationActions::startOnboarding` for exactly
     * this caller: `$startedBy` is null when a rule causes it, and the column
     * is nullable for that reason — "who started it" has no answer when nobody
     * did, and borrowing the rule's author would attribute an act to somebody
     * who did not perform it. So null is passed, not the author.
     *
     * Every state rule is theirs — not already carrying paperwork, not
     * withdrawn, not lapsed, a published version with a signature field — and
     * they refuse in their own words, which the run log prints as-is.
     *
     * ALREADY STARTED IS A SUCCESS. A rule firing twice on one invitation finds
     * the paperwork already open; that is the rule having done its job, not a
     * failure toward the five that switch it off. Asked before calling, because
     * their refusal for it is the same exception class as the real ones.
     */
    private function startOnboarding(array $action, ?Model $subject): array
    {
        $type = 'onboarding.start';

        if (! $subject instanceof Invitation) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'This step only works on an invitation, and this rule is about something else.'];
        }

        $contract = Contract::query()->find((string) ($action['contract_id'] ?? ''));

        if ($contract === null) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'The contract template this step names no longer exists.'];
        }

        $alreadyOpen = Onboarding::query()
            ->where('invitation_id', $subject->getKey())
            ->where('status', '!=', Onboarding::CANCELLED)
            ->exists();

        if ($alreadyOpen) {
            return ['type' => $type, 'ok' => true,
                'detail' => 'The paperwork for this invitation is already open.'];
        }

        try {
            app(InvitationActions::class)->startOnboarding($subject, $contract, startedBy: null);
        } catch (CannotActOnInvitation $refused) {
            return ['type' => $type, 'ok' => false, 'detail' => $refused->getMessage()];
        }

        return ['type' => $type, 'ok' => true,
            'detail' => "Opened the onboarding against \"{$contract->name}\"."];
    }

    /**
     * Write a suggested reply to the review and leave it for a person.
     *
     * A published reply appears on Google under the business's own name, in
     * public, permanently — which is why ReviewReply has no "approved" state
     * and publication is a person's act. This step writes a DRAFT and nothing
     * else; nothing here can publish, and that is the whole point of it.
     *
     * NEVER OVER ANOTHER REPLY. Google allows one per review, so there is one
     * row. A published one must never be touched, and a draft somebody is
     * working on must not be replaced by a template. Either way the review
     * already has its reply, so this succeeds and says so — a rule firing
     * again on the same review is not a failure.
     */
    private function draftReviewReply(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $type = 'draft_reply';

        if (! $subject instanceof Review) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'This step only works on a review, and this rule is about something else.'];
        }

        if ($subject->reply()->exists()) {
            return ['type' => $type, 'ok' => true,
                'detail' => 'This review already has a reply or a draft, so it was left as it is.'];
        }

        $body = trim($this->interpolate((string) ($action['message'] ?? ''), $event, $subject));

        if ($body === '') {
            return ['type' => $type, 'ok' => false, 'detail' => 'This step needs the words of the reply.'];
        }

        ReviewReply::create([
            'organization_id' => $subject->organization_id,
            'review_id' => $subject->getKey(),
            'body' => $body,
            'status' => ReviewReply::STATUS_DRAFT,
            'drafted_by' => ReviewReply::BY_AUTOMATION,
        ]);

        return ['type' => $type, 'ok' => true,
            'detail' => 'Drafted a reply. It is waiting for a person to read and publish it.'];
    }

    /**
     * Book a follow-up job for the same customer, at the same franchise.
     *
     * Built the way `missed.create_job` builds one — numbered, first allowed
     * status, audited as created by an automation — because that is the
     * precedent a person reading the job list already sees.
     *
     * EMITTED WITH `causedBy`, which is the whole loop story. A rule on "job
     * created" whose step creates a job sets itself off; the event carries its
     * cascade depth and the engine stops it at MAX_DEPTH, and the shared action
     * budget stops it sooner. A misconfigured rule makes a handful of jobs,
     * not an unbounded number.
     *
     * A JOB OR A CUSTOMER. A job's follow-up belongs to that job's customer and
     * franchise; a customer's new job belongs to the customer and the franchise
     * accountable for them. Anything else has no customer to book it for, and
     * says so rather than inventing one.
     */
    private function createFollowUpJob(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $type = 'job.create';

        [$customer, $franchise] = match (true) {
            $subject instanceof Job => [$subject->customer, $subject->franchise],
            $subject instanceof Customer => [$subject, $subject->owningFranchise],
            default => [null, null],
        };

        if ($customer === null || $franchise === null) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'A job needs a customer to book it for, and this rule is about neither '
                    .'a job nor a customer, so nothing was booked.'];
        }

        $title = trim($this->interpolate((string) ($action['title'] ?? ''), $event, $subject));

        if ($title === '') {
            return ['type' => $type, 'ok' => false, 'detail' => 'This step needs a title for the job.'];
        }

        $status = Job::allowedStatuses($franchise)[0] ?? null;

        if ($status === null) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'This organization has no job statuses configured.'];
        }

        // Optional and whole days only: "in 30 days" is what a follow-up rule
        // means, and a blank leaves it for somebody to schedule, which is the
        // honest default for a job nobody has spoken to the customer about.
        $inDays = trim((string) ($action['in_days'] ?? ''));

        // COUNTED FROM THE FRANCHISE'S TODAY. The app runs in UTC, and a rule
        // firing at 10 PM in New York is already tomorrow in UTC — so counting
        // from `now()` landed "in 1 day" a day late for anything that fired in
        // the evening. `scheduled_for` is stored as naive wall-clock time and
        // read back in the franchise's zone (FranchiseWallTime), so computing
        // in that zone makes the stored digits the ones a person reads: that
        // day, nine in the morning where the franchise is.
        $zone = is_string($franchise->timezone) && $franchise->timezone !== ''
            ? $franchise->timezone
            : (string) config('app.timezone');

        $scheduledFor = ctype_digit($inDays)
            ? now($zone)->addDays((int) $inDays)->startOfDay()->setHour(9)
            : null;

        $job = DB::transaction(function () use ($customer, $franchise, $title, $status, $scheduledFor, $event, $subject) {
            $job = Job::create([
                'franchise_id' => $franchise->id,
                'customer_id' => $customer->id,
                'number' => $this->numbers->next(RecordNumberGenerator::JOBS, $franchise),
                'title' => $title,
                'status' => $status,
                'scheduled_for' => $scheduledFor,
                'currency' => strtoupper($franchise->currency ?: 'USD'),
            ]);

            $this->audit->log(
                action: 'job.created_by_automation',
                target: $job,
                metadata: [
                    'caused_by_event' => $event->getKey(),
                    'trigger' => $event->event_key,
                    'follows' => $subject?->getKey(),
                ],
            );

            $this->emitter->emit(
                'job.created',
                $job,
                ['after' => $job->only(['status', 'title', 'franchise_id', 'customer_id', 'amount_minor'])],
                causedBy: $event,
            );

            return $job;
        });

        return ['type' => $type, 'ok' => true,
            'detail' => "Booked job {$job->number}: {$title}"
                .($scheduledFor !== null ? ', for '.$scheduledFor->format('j M').'.' : ', not yet scheduled.')];
    }

    /**
     * Hand the customer to another franchise — the audited act, not a field.
     *
     * "Update a field" refuses `owning_franchise_id` and says why: reassigning
     * accountability is a separate audited act (§5.2), and a rule must not do
     * silently what an endpoint refuses loudly. This IS that act — it goes
     * through ReassignCustomerOwner, the one path the endpoint uses too, so the
     * audit entry, the `customer.owner_reassigned` event and any check added to
     * it later all apply to a rule exactly as they do to a person.
     *
     * AS THE RULE'S AUTHOR. A rule fires with nobody at a screen, so the same
     * policy the endpoint asks about the requester is asked about whoever wrote
     * the rule. Somebody cannot hand a customer to a franchise they could not
     * have handed it to themselves by writing a rule to do it.
     *
     * ALREADY THERE IS A SUCCESS. A rule firing twice on one customer finds it
     * already moved the second time; failing that would count toward the five
     * that switch a rule off, for a rule doing exactly what it was asked.
     */
    private function reassignOwner(array $action, ?Model $subject, ?Automation $automation): array
    {
        $type = 'reassign_owner';

        if (! $subject instanceof Customer) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'This step only works on a customer, and this rule is about something else.'];
        }

        $target = preg_replace('/^franchise:/', '', (string) ($action['franchise'] ?? ''));
        $franchise = $target === '' ? null : Franchise::query()->find($target);

        if ($franchise === null) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'The franchise this step hands customers to no longer exists.'];
        }

        $author = $automation?->activeVersion?->created_by !== null
            ? User::query()->find($automation->activeVersion->created_by)
            : null;

        if ($author === null) {
            return ['type' => $type, 'ok' => false,
                'detail' => 'The person who built this rule no longer works here, so the customer was '
                    .'not moved. Open the rule and save it again to take it over.'];
        }

        $decision = Gate::forUser($author)->inspect('reassignOwner', [$subject, $franchise]);

        if ($decision->denied()) {
            return ['type' => $type, 'ok' => false, 'detail' => (string) $decision->message()];
        }

        try {
            app(ReassignCustomerOwner::class)(
                $subject,
                $franchise,
                // THE RULE IS THE REASON. "Who was accountable in March" has to
                // stay answerable, and so does "why" — a person gives one, and
                // a rule's is its name.
                'Moved by the automation "'.($automation?->name ?? 'unnamed').'".',
            );
        } catch (InvalidArgumentException) {
            return ['type' => $type, 'ok' => true,
                'detail' => "{$franchise->name} already owns this customer."];
        }

        return ['type' => $type, 'ok' => true, 'detail' => "Handed the customer to {$franchise->name}."];
    }

    /**
     * A notification that only the organization's administrators receive.
     *
     * THE NOTIFY ARM, NARROWED - not a second notification path. Reach, the
     * company's switch, the in-app-only choice and the recipient cap all live
     * in `notify` and the dispatcher, and a copy here would be a second place
     * for any of them to be wrong.
     *
     * THE GUARD THIS ONE NEEDS AND NOTIFY DOES NOT. An empty role list means
     * "narrow nothing", which is right for `notify` and catastrophic here: an
     * organization with no administrator role would have its "tell the
     * administrators" step tell EVERYBODY. So no administrator role is a
     * refusal in words, never a fallback to all.
     */
    private function notifyAdministrators(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $roleIds = Role::query()->where('is_administrator', true)->pluck('id')->all();

        if ($roleIds === []) {
            return [
                'type' => 'notify_administrators', 'ok' => false,
                'detail' => 'This business has no administrator role, so there was nobody to tell. '
                    .'Nobody else was told instead.',
            ];
        }

        $result = $this->notify(['role_ids' => $roleIds] + $action, $event, $subject);

        return ['type' => 'notify_administrators'] + $result;
    }

    private function notify(array $action, AutomationEvent $event, ?Model $subject): array
    {
        $message = $action['message'] ?? null;

        if ($message === null) {
            throw new InvalidArgumentException('notify needs a message.');
        }

        $title = $this->interpolate($message, $event, $subject);

        // A NOTIFICATION NEVER EMAILS. Jose, 2026-09-28: "the toggle should
        // not exist at all ... if i wanted an automation to send an email i
        // would use send an email step". The old negative switch (in_app_only,
        // off = email) had a lead rule emailing nine people ~370 times an hour.
        //
        // The dispatcher refuses email for source 'automation' itself
        // (NotificationDispatcher::mayEmail). NOT by passing inAppOnly here:
        // that flag also silences PUSH, and would stop every rule's alert
        // reaching a phone. Stored in_app_only / send_email values are ignored.
        $inAppOnly = false;

        // Strings, filtered, re-indexed: an empty box in the builder must mean
        // "narrow nothing" rather than "a role whose id is the empty string",
        // which would match nothing and tell nobody.
        $roleIds = array_values(array_filter(array_map(
            fn ($id) => trim((string) $id),
            self::idList($action['role_ids'] ?? []),
        ), fn (string $id) => $id !== ''));

        // Named people, beside the roles. Same filtering as the role list: an
        // empty box means "narrow nothing", never "somebody whose id is empty".
        $userIds = array_values(array_filter(array_map(
            fn ($id) => trim((string) $id),
            self::idList($action['user_ids'] ?? []),
        ), fn (string $id) => $id !== ''));

        // "Also tell the task's assignee". Nobody assigned, and nobody else
        // named, is said and skipped: an empty audience would tell everybody.
        if (filter_var($action['to_assignee'] ?? false, FILTER_VALIDATE_BOOL)) {
            $assignee = $this->assigneeOf($event, $subject);
            if ($assignee !== null) {
                $userIds[] = $assignee->getKey();
            } elseif ($userIds === [] && $roleIds === []) {
                return ['type' => 'notify', 'ok' => true, 'skipped' => true, 'detail' => 'The task has nobody assigned, so nobody was told.'];
            }
        }

        /*
         * A BAD ROLE ID IS A REFUSAL, NOT A 500.
         *
         * The dispatcher throws when a rule names a role that does not exist,
         * and it is right to: telling everybody instead would be the noise the
         * role was chosen to avoid. But thrown out of here it reached the
         * browser as "Server Error", which tells the rule's author nothing and
         * looks like the app is broken rather than like their rule is.
         *
         * Measured on Jose's live rule, 2026-09-17: three edits had stored the
         * role list as ["[[[]]]"] and pressing Test answered a 500 with the
         * reason only in the server log.
         */
        try {
            // THE SECOND LINE (Jose, 2026-10-06, "Source Names for
            // notification"): the number, length and place under the headline,
            // or the reply itself. Interpolated like the title; empty is none.
            $details = trim($this->interpolate((string) ($action['details'] ?? $action['body'] ?? ''), $event, $subject));

            // A TITLE FITS ITS COLUMN (varchar 255). A long {{field}}, such as
            // a customer's whole message, failed the insert and switched
            // "Customer Waiting Warning" off. Cut with an ellipsis; the full
            // text goes in the body, ahead of any second line.
            if (mb_strlen($title) > 255) {
                $details = trim($title."
".$details);
                $title = mb_substr($title, 0, 254).'…';
            }

            $sent = $this->notifications->dispatch(
                title: $title,
                body: $details === '' ? null : $details,
                subject: $subject,
                source: 'automation',
                eventId: $event->getKey(),
                inAppOnly: $inAppOnly,
                roleIds: $roleIds,
                userIds: $userIds,
                // "Mark as urgent (red)": a red icon in the bell and the urgent push channel.
                urgent: (bool) ($action['urgent'] ?? false),
            );
        } catch (InvalidArgumentException $refusal) {
            return ['type' => 'notify', 'ok' => false, 'detail' => $refusal->getMessage()];
        }

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
                    ? ($roleIds !== []
                        // THREE ZEROES, THREE ERRANDS. This one names the ROLE
                        // as what narrowed it, because that is the switch the
                        // author can go and change - the same reason the line
                        // below stopped saying "reach" when a company had simply
                        // switched notifications off.
                        ? 'Nobody in the chosen role has reach over that record, so there '
                            ."was no one to tell: {$title}"
                        : "Nobody has reach over that record, so there was no one to tell: {$title}")
                    : 'Automation notifications are switched off for this company, so nobody '
                        ."was told: {$title}")
                : "Told {$sent} ".($sent === 1 ? 'person' : 'people').": {$title}",
        ];
    }

    /**
     * `{{ field }}` from the record, then from the event, and nothing else.
     *
     * Not a template engine, deliberately. Tenant-authored strings evaluated as
     * code is remote code execution wearing a friendly name; this only ever
     * substitutes values that are already in hand.
     *
     * ── WHY THE EVENT IS READ AND NOT ONLY THE RECORD ──────
     *
     * The condition builder offers the rule author the EVENT's fields — that is
     * what `triggerFields()` derives and what the "only if" dropdown lists. So
     * the names a person has just been shown, and has already typed once into a
     * condition, are event field names. Resolving only the record's attributes
     * meant the field somebody was invited to test could not be inserted into
     * the message about it, with no hint that the two lists differed.
     *
     * Same precedence as a recipient field, for the same reason: an update
     * carries `after`, a flat event carries its keys at the top.
     *
     * AN UNKNOWN FIELD STILL BECOMES AN EMPTY STRING rather than an error. A
     * half-written message that goes out beats a rule that fails at 3am over a
     * typo — but it is also why the builder has to show which names exist,
     * because a silent blank is the one failure a person reads and cannot
     * explain.
     */
    private function interpolate(string $template, AutomationEvent $event, ?Model $subject, bool $forMarkdown = false): string
    {
        // "Book online now: {{booking_link}}" is a line, not a message: with no
        // booking page it is dropped and the rest still goes (Leads, 2026-10-13).
        // The same for an offered slot with no open window (2026-10-19).
        foreach (['booking_link', 'offered_slot'] as $field) {
            if (preg_match('/\{\{\s*'.$field.'\s*\}\}/i', $template)
                && blank($subject?->getAttribute($field))) {
                $template = implode("\n", array_filter(
                    explode("\n", $template),
                    fn (string $line) => ! preg_match('/\{\{\s*'.$field.'\s*\}\}/i', $line),
                ));
            }
        }

        // Only the fields EzBizi composes itself keep their Markdown; anything
        // else may be a stranger's words and is escaped to read as text.
        $composed = $forMarkdown
            ? (app(ModuleRegistry::class)->findTrigger($event->event_key)?->composedFields ?? [])
            : [];

        return preg_replace_callback('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', function ($m) use ($subject, $event, $forMarkdown, $composed) {
            $value = $subject?->getAttribute($m[1]) ?? $this->valueFromEvent($m[1], $event);
            $text = is_scalar($value) ? (string) $value : '';

            return $forMarkdown && ! in_array($m[1], $composed, true) ? self::escapeMarkdown($text) : $text;
        }, $template);
    }

    /** Backslash every Markdown control character, so the text renders as itself. */
    public static function escapeMarkdown(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~])/', '\\\\$1', $text);
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
    /**
     * A task step, written as the rule's creator standing in the rule's own
     * place (Task Board, 2026-10-13): TaskBoards::canEdit reads write authority
     * from the current view, and a queued run has none, so the rule's scope is
     * put in front of it as the view for the length of the step. A board the
     * creator could not edit by hand is refused the same way.
     *
     * @param  callable(User): string  $write  returns the run-log sentence
     */
    private function onTaskBoard(string $type, ?Automation $automation, ?TaskBoard $board, callable $write): array
    {
        if ($board === null) {
            return ['type' => $type, 'ok' => false, 'detail' => 'There is no task board to write to.'];
        }

        [$actor] = $this->taskContext($automation);
        $previous = app('request');
        $request = \Illuminate\Http\Request::create('/');
        $request->setUserResolver(fn () => $actor);
        $request->headers->set('X-EzBizi-View', $automation?->scope_type
            ? $automation->scope_type.':'.$automation->scope_id
            : ViewScope::ORGANIZATION);
        app()->instance('request', $request);

        try {
            if (! app(TaskBoards::class)->canEdit($actor, $board)) {
                return ['type' => $type, 'ok' => false, 'detail' => 'The person who made this rule cannot change that task board.'];
            }

            return ['type' => $type, 'ok' => true, 'detail' => $write($actor)];
        } catch (ValidationException $e) {
            return ['type' => $type, 'ok' => false, 'detail' => collect($e->errors())->flatten()->first() ?? $e->getMessage()];
        } finally {
            app()->instance('request', $previous);
        }
    }

    /** A task on the event's franchise's or company's board (else the assignee's own), given to the assignee; about the rule's record. */
    private function createTask(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        $title = trim($this->interpolate((string) ($action['title'] ?? ''), $event, $subject));
        if ($title === '') {
            return ['type' => 'task.create', 'ok' => false, 'detail' => 'The task needs a title.'];
        }

        $assignee = $this->userFromStored($action['assignee'] ?? null);
        $scope = $event->scope_snapshot ?? [];
        // The PLACE's board, with the person as assignee: a personal board
        // only its owner may write, so a rule giving a task to somebody else
        // was refused on theirs. Their own board only when there is no place.
        $owner = (($scope['type'] ?? null) === 'franchise' ? Franchise::query()->find($scope['id'] ?? null) : null)
            ?? (($scope['type'] ?? null) === 'company' ? Company::query()->find($scope['id'] ?? null) : null)
            ?? ($subject?->getAttribute('franchise_id') ? Franchise::query()->find($subject->getAttribute('franchise_id')) : null)
            ?? $assignee;

        if ($owner === null) {
            return ['type' => 'task.create', 'ok' => false, 'detail' => 'No board to put the task on: give it to somebody, or use a trigger about a place.'];
        }

        $about = $subject ? array_search($subject::class, Task::ABOUT, true) : false;
        $board = app(TaskBoards::class)->ensureFor($owner);

        return $this->onTaskBoard('task.create', $automation, $board, function (User $actor) use ($board, $title, $assignee, $about, $subject, $automation) {
            app(TaskWriter::class)->create($board, [
                'title' => mb_substr($title, 0, 255),
                'assignee_user_id' => $assignee?->getKey(),
                'about_type' => $about === false ? null : $about,
                'about_id' => $about === false ? null : $subject->getKey(),
            ], $actor, $automation?->getKey());

            return 'Added the task "'.$title.'".';
        });
    }

    private function assignTask(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        if (! $subject instanceof Task) {
            return ['type' => 'task.assign', 'ok' => false, 'detail' => 'This step needs a trigger about a task.'];
        }

        $stored = (string) ($action['user_field'] ?? '');
        $user = $this->userFromStored($stored)
            ?? User::query()->where('is_active', true)->find($this->valueFromEvent($stored, $event));
        if ($user === null) {
            return ['type' => 'task.assign', 'ok' => false, 'detail' => 'Nobody to give the task to.'];
        }

        return $this->onTaskBoard('task.assign', $automation, $subject->board, function (User $actor) use ($subject, $user) {
            app(TaskWriter::class)->update($subject, ['assignee_user_id' => $user->getKey()], $actor);

            return 'Gave the task to '.$user->name.'.';
        });
    }

    /** Task Board recipes (Jose, 2026-10-13): "when status becomes Done, move it to Finished". TaskWriter checks the group is on the task's board. */
    private function moveTaskToGroup(array $action, ?Model $subject, ?Automation $automation): array
    {
        if (! $subject instanceof Task) {
            return ['type' => 'task.move_group', 'ok' => false, 'detail' => 'This step needs a trigger about a task.'];
        }
        $groupId = (string) ($action['group_id'] ?? '');
        if ($subject->group_id === $groupId) {
            return ['type' => 'task.move_group', 'ok' => true, 'skipped' => true, 'detail' => 'The task is already in that group.'];
        }

        return $this->onTaskBoard('task.move_group', $automation, $subject->board, function (User $actor) use ($subject, $groupId) {
            app(TaskWriter::class)->update($subject, ['group_id' => $groupId], $actor);

            return 'Moved the task to another group.';
        });
    }

    /**
     * Reports' daily and weekly texts as a scheduled rule (A1 Leads takeover,
     * 2026-10-20), WhatsApped 1:1 through WhatsAppGroups::deliverTextToUser.
     * The texts carry money, so the rule's creator must hold reports.view.
     */
    private function reportText(array $action, ?Automation $automation): array
    {
        $type = 'report.text';
        [$creator] = $this->taskContext($automation);
        if (! $creator->hasPermission('reports.view')) {
            return ['type' => $type, 'ok' => false, 'detail' => 'The person who made this rule cannot see reports, so it sends none.'];
        }
        $kind = (string) ($action['kind'] ?? '');
        if (! array_key_exists($kind, \App\Support\Reports\Texts\DailyTexts::KINDS)) {
            return ['type' => $type, 'ok' => false, 'detail' => 'Choose which report to send.'];
        }
        $from = (string) ($action['from'] ?? '');
        $number = \App\Models\WhatsAppNumber::query()->when($from !== '', fn ($q) => $q->where('display_number', $from))->first();
        if ($number === null) {
            return ['type' => $type, 'ok' => false, 'detail' => 'This organization has no WhatsApp number to send from.'];
        }

        $org = \App\Models\Organization::query()->findOrFail($automation->organization_id);
        $zone = (string) (app(\App\Support\Settings\SettingsResolver::class)->get('timezone', $org) ?: config('app.timezone'));
        $day = \Carbon\CarbonImmutable::now($zone);
        $branches = match ($automation->scope_type) {
            'franchise' => [$automation->scope_id],
            'company' => \App\Models\Franchise::query()->where('company_id', $automation->scope_id)->pluck('id')->all(),
            default => null,
        };
        $text = app(\App\Support\Reports\Texts\DailyTexts::class)->render($kind, $org, $day, $branches);
        $title = \App\Support\Reports\Texts\DailyTexts::KINDS[$kind].' · '.$day->format('M j');

        $sent = 0;
        foreach (User::query()->where('is_active', true)->whereIn('id', self::idList($action['user_ids'] ?? []))->get() as $user) {
            // Once per rule, report, day and person: a retried or re-run rule does not send it twice.
            if (! \Illuminate\Support\Facades\Cache::add("report.text:{$automation->id}:{$kind}:{$day->toDateString()}:{$user->id}", true, now()->addDays(2))) {
                continue;
            }
            try {
                $sent += app(\App\Support\Social\WhatsAppGroups::class)->deliverTextToUser($number, $user, $title, $text) ? 1 : 0;
            } catch (RuntimeException $refusal) {
                return ['type' => $type, 'ok' => false, 'detail' => $refusal->getMessage()];
            }
        }

        return $sent === 0
            ? ['type' => $type, 'ok' => true, 'skipped' => true, 'detail' => 'Nobody chosen has a phone number, so nothing was sent.']
            : ['type' => $type, 'ok' => true, 'detail' => "Handed \"{$title}\" to WhatsApp for {$sent} ".($sent === 1 ? 'person' : 'people').'.'];
    }

    /** `user:<id>` as the pickers store it, an active person, or null. */
    private function userFromStored(mixed $stored): ?User
    {
        return is_string($stored) && str_starts_with($stored, 'user:')
            ? User::query()->where('is_active', true)->find(substr($stored, 5))
            : null;
    }

    /** The task's assignee: the event's assignee_user_id, else the subject's. Active people only. */
    private function assigneeOf(AutomationEvent $event, ?Model $subject): ?User
    {
        $id = data_get($event->payload, 'assignee_user_id')
            ?? data_get($event->payload, 'after.assignee_user_id')
            ?? $subject?->getAttribute('assignee_user_id');

        return is_string($id) && $id !== '' ? User::query()->where('is_active', true)->find($id) : null;
    }

    /**
     * A list of ids, or the comma-joined string an older builder saved for
     * named people: read as one id, it told nobody it named.
     *
     * @return list<string>
     */
    private static function idList(mixed $value): array
    {
        return is_string($value) ? explode(',', $value) : array_values((array) $value);
    }

    /**
     * A Communication › Alerts pop-up from a rule (Jose, 2026-10-13: complaint
     * calls). Exactly a person's alert - one Alert, each recipient's copy a
     * notification with source `alert` - sent as the rule's creator, who must
     * be allowed to send alerts by hand.
     */
    private function sendAlert(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        [$actor] = $this->taskContext($automation);
        if (! $actor->hasPermission('phone.alerts.view')) {
            return ['type' => 'alert.send', 'ok' => false, 'detail' => 'The person who made this rule may not send alerts.'];
        }

        $message = mb_substr(trim($this->interpolate((string) ($action['message'] ?? ''), $event, $subject)), 0, 500);
        $named = array_values(array_unique(array_filter(array_map('trim', self::idList($action['user_ids'] ?? [])))));
        $people = User::query()->where('is_active', true)->whereIn('id', $named)->get();
        // Skipped, not refused as a person's send is: a rule keeps alerting
        // the rest. Said, so whoever reads the run log knows to fix the list.
        $skipped = count($named) - $people->count();

        if ($message === '' || $people->isEmpty()) {
            return ['type' => 'alert.send', 'ok' => false, 'detail' => $message === '' ? 'The alert has no message.' : 'Nobody to alert.'];
        }

        $alert = \App\Models\Alert::create(['organization_id' => $actor->organization_id, 'sender_id' => $actor->id, 'message' => $message]);
        $told = 0;
        foreach ($people as $person) {
            $told += $this->notifications->dispatch(
                title: $alert->message,
                body: 'From the automation "'.($automation?->name ?? 'a rule').'"',
                subject: $alert,
                onlyTo: $person,
                source: 'alert',
            ) > 0 ? 1 : 0;
        }

        return ['type' => 'alert.send', 'ok' => true, 'detail' => "Alerted {$told} ".($told === 1 ? 'person' : 'people').'.'
            .($skipped > 0 ? " {$skipped} named ".($skipped === 1 ? 'person is' : 'people are').' no longer active here and were skipped.' : '')];
    }

    /**
     * A text to the lead from the branch's cleared line, written into the
     * lead's thread so the answer is seen there (LeadTexts, Leads 2026-10-13).
     * No phone on the lead is skipped, not failed: a web form without one is
     * ordinary, and a failure would count toward switching the rule off.
     */
    private function textLead(array $action, AutomationEvent $event, ?Model $subject, ?Automation $automation): array
    {
        if (! $subject instanceof Lead) {
            return ['type' => 'lead.text', 'ok' => false, 'detail' => 'This step needs a trigger about an enquiry.'];
        }

        if (Lead::e164($subject->phone) === null) {
            return ['type' => 'lead.text', 'ok' => true, 'skipped' => true, 'detail' => 'The enquiry has no phone number, so no text was sent.'];
        }

        [$actor] = $this->taskContext($automation);

        try {
            app(\App\Support\Leads\LeadTexts::class)->send($subject, $this->interpolate((string) ($action['body'] ?? ''), $event, $subject), $actor, automatic: true);
        } catch (\RuntimeException $e) {
            return ['type' => 'lead.text', 'ok' => false, 'detail' => $e->getMessage()];
        }

        return ['type' => 'lead.text', 'ok' => true, 'detail' => 'Texted the customer; it is on the enquiry.'];
    }
}
