<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * One thing that happened, waiting to be delivered (plan §5.5).
 *
 * The transactional outbox. Written inside the same transaction as the business
 * change, so the two cannot disagree: roll back and the event goes with it,
 * commit and the event is already durable.
 *
 * Dispatching to a queue from inside the transaction is the bug this avoids —
 * the worker can pick the job up before the commit lands and find nothing, or
 * the commit can fail after the job was queued and act on something that never
 * happened.
 */
class AutomationEvent extends Model implements HoldsPersonalData
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    // ── §9 data inventory ────────────────────────────────────────

    /*
     * This table was once declared as holding no personal data. It never was
     * true: the payload is what rules read, and rules are about people. Measured
     * on production 2026-09-24, 104,946 rows: 66,172 lead events carry the
     * enquirer's phone, 773 phone events the caller's number (2 an SMS body),
     * 1,394 `email.received` the sender's address, name and subject, 41 the
     * invitee's email, and 1,800 rows name the employee who acted in
     * `actor_label`. Being only StatesRetention kept every one of them out of
     * the purge, so an erased customer's number stayed here up to three months.
     *
     * ── Why the payload is not stripped at the emitter instead ───────
     *
     * Rules read these keys today: `{{from_e164}}` in the missed-call templates,
     * conditions on `from_address`, the condition picker discovering fields
     * from events it has seen. Resolving them at run time, the way `customer:`
     * recipients are, would break every live rule that reads them.
     */
    public static function personalDataPurpose(): string
    {
        return 'What happened, kept for three months so an automation can act on it and '
            .'"why did my automation not fire?" stays answerable. It copies the details a '
            .'rule reads — a caller\'s number, an email\'s sender, an enquirer\'s name.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('payload', PersonalDataEntry::CONTENT,
                'The details of what happened, copied for the rules that read them: the '
                .'numbers on a call or text and its body, the sender, name and subject of an '
                .'email, a customer\'s name, phone and email when they are added or changed, '
                .'an invitee\'s email.',
                /*
                 * Dropped rather than blanked, for the runs. `automation_runs`
                 * copies payload values into `condition_trace.actual`, and the
                 * cascade from here is the one thing that reaches them. Blanking
                 * the payload would leave those copies behind.
                 */
                Erasure::drop('An event about this person is removed with the runs it caused, '
                    .'which copied its details; nothing else is recorded here about them.')),
            new PersonalDataEntry('actor_label', PersonalDataEntry::IDENTITY,
                'The name and email of the employee whose action raised the event, as the '
                .'audit log records it.',
                Erasure::erase('Former staff')),
            new PersonalDataEntry('payload.after.contact_name, payload.after.reviewer_name',
                PersonalDataEntry::IDENTITY,
                'The name an enquirer gave, or a Google reviewer\'s public name.',
                Erasure::keep('An enquirer and a reviewer are not people this system is asked to '
                    .'erase, the same answer their own tables give. Where the enquiry\'s phone is '
                    .'an erased customer\'s, the row goes with that customer. Otherwise it expires '
                    .'with the row, within three months.')),
        ];
    }

    /**
     * Rows about this person, and rows they only triggered.
     *
     * About them: they are the event's subject, or the payload holds their
     * phone or email in a key listed by `identifiers()`. Those are dropped.
     * Triggered by them: `actor_id`. Those keep their history and lose the
     * label, which `personalDataToErase` tells the Anonymiser row by row.
     *
     * Exact match, as PhoneCall matches: a number the customer used before the
     * one on their record now is not found.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        if (! $subject instanceof Customer && ! $subject instanceof User) {
            return null;
        }

        return static::query()->where(function (Builder $q) use ($subject) {
            $q->where(fn (Builder $s) => $s
                ->where('subject_type', $subject::class)
                ->where('subject_id', $subject->getKey()));

            foreach (self::identifiers($subject) as $path => $value) {
                $q->orWhere('payload->'.str_replace('.', '->', $path), $value);
            }

            if ($subject instanceof User) {
                $q->orWhere('actor_id', $subject->getKey());
            }
        });
    }

    /** `[]` drops the row (it is about them); `['actor_label']` erases the name of who acted. */
    public function personalDataToErase(Model $subject): array
    {
        $about = ($this->subject_type === $subject::class && $this->subject_id === $subject->getKey())
            || array_intersect_assoc(Arr::dot($this->payload ?? []), self::identifiers($subject)) !== [];

        return $about ? [] : ['actor_label'];
    }

    /**
     * Where the emitters put a phone or an email, and this person's value there.
     *
     * The emitters write these keys: phone events `after.from_e164`/`to_e164`,
     * lead and customer events `after.phone`, `email.received` a top-level
     * `from_address`, customer and invitation events `after.email`; Field a
     * top-level `user_id`, training and onboarding `after.user_id`.
     *
     * @return array<string, string> payload dot path => value
     */
    private static function identifiers(Model $subject): array
    {
        $phone = (string) ($subject->getAttribute('phone') ?? '');
        $email = (string) ($subject->getAttribute('email') ?? '');

        return array_filter([
            'after.from_e164' => $subject instanceof Customer ? $phone : '',
            'after.to_e164'   => $subject instanceof Customer ? $phone : '',
            'after.phone'     => $subject instanceof Customer ? $phone : '',
            'from_address'    => $email,
            'after.email'     => $email,
            // An employee's id, where the event is about THEM: Field puts it
            // beside where they were (latitude, longitude, map_url), training
            // and onboarding beside their result. The id is not personal data
            // on its own, but the row it sits in describes that person.
            'user_id'         => $subject instanceof User ? (string) $subject->getKey() : '',
            'after.user_id'   => $subject instanceof User ? (string) $subject->getKey() : '',
        ], fn (string $v) => $v !== '');
    }

    /**
     * Three months, and it takes the runs with it.
     *
     * This is the table §10 was written about: until StatesRetention existed
     * there was nowhere to say anything about it. It was therefore not a table
     * with a generous policy — it was a table nobody had ever decided about,
     * taking a row for every business change in every organization, forever.
     *
     * Three months matches business audit, deliberately: this is the same
     * history seen from the automation side, and two different windows over one
     * set of facts would mean "why did my rule not fire in April?" being
     * answerable from one table and not the other.
     *
     * `automation_runs.event_id` cascades from here, so expiring an event expires
     * its runs in the same statement. That is why AutomationRun states
     * `withParent` rather than a window of its own — one number, one place, and
     * a run can never outlive the event it explains.
     *
     * The sweep is bounded to DELIVERED or FAILED events by the command, because
     * an undelivered event is work still owed rather than history.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'occurred_at',
            'The outbox of what happened, kept so "why did my automation not fire?" stays '
            .'answerable — the same three months as the business audit trail it mirrors. '
            .'Expiring an event removes the runs it caused, by the cascade.'
        );
    }

    public const UPDATED_AT = null;
    public const CREATED_AT = null;   // occurred_at is the real timestamp

    /**
     * How deep a cascade may go.
     *
     * An automation updating a field emits an update event, which may trigger
     * another automation. Five is enough for any rule anyone means to write and
     * short enough that a loop stops being expensive quickly.
     */
    public const MAX_DEPTH = 5;

    /**
     * Total ACTIONS one originating event may cause across the whole cascade.
     *
     * Actions, not runs. Counting runs made this a limit of 50 *rules firing*,
     * each of which can carry any number of actions — so the real ceiling on
     * outbound calls was unbounded, which is precisely what a budget is for.
     */
    public const ACTION_BUDGET = 50;

    /** Give up after this many delivery attempts and dead-letter it. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'organization_id', 'event_key', 'subject_type', 'subject_id', 'payload',
        'actor_id', 'actor_label', 'root_event_id', 'causation_id', 'depth',
        'correlation_id', 'occurred_at', 'available_at', 'scope_snapshot',
    ];

    /**
     * `delivered_at` MEANS DELIVERED TO THE ENGINE. IT IS NOT ABOUT EMAIL.
     *
     * It is the moment this outbox row was handed to the rules that act on it —
     * nothing more. It says nothing about whether anything reached a person, and
     * the application cannot know that at all: there is no SES event ingestion,
     * no SNS route and no bounce handler, so delivered, bounced and opened are
     * not facts EzBizi holds about any message it sends.
     *
     * Written here because the NAME argues for the wrong word. The first screen
     * to show an email-sending automation step will be reading this table, one
     * column away from a field called `delivered_at`, and "Delivered" is the
     * single most natural label to reach for and the one thing that cannot be
     * said. The vocabulary that CAN be said is: Sent (handed over), Couldn't
     * send and which reason, and — for a box whose transport goes nowhere,
     * see `MailStatus` — that this installation is not sending mail at all.
     *
     * `available_at`, `attempts` and `failed_at` beside it are the honest pair
     * to that: they describe waiting to RUN, which is real and observable here.
     * Waiting to run and waiting to arrive look identical on a screen and mean
     * opposite things to somebody working out why a customer never heard back.
     *
     * Raised by the UI/UX session on 2026-09-14, after measuring that the app
     * cannot observe delivery.
     */
    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'scope_snapshot' => 'array',
            'version_ids' => 'array',
            'processing_until' => 'datetime',
            'occurred_at'  => 'datetime',
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at'    => 'datetime',
            'depth'        => 'integer',
            'attempts'     => 'integer',
        ];
    }

    /**
     * The originating event of this cascade.
     *
     * Its own id when it started the cascade — so every event in a chain shares
     * one root, and the action budget is enforced across the whole thing rather
     * than per hop.
     */
    public function rootId(): string
    {
        return $this->root_event_id ?? $this->getKey();
    }

    public function isTooDeep(): bool
    {
        return $this->depth >= self::MAX_DEPTH;
    }

    /**
     * How many actions this cascade has already performed.
     *
     * Depth alone does not stop A→B→A across separate delayed runs, and it does
     * nothing about a rule that fans out sideways — one event producing fifty.
     * The budget covers both.
     */
    public function actionsSpent(): int
    {
        $root = $this->rootId();

        return static::query()
            ->join('automation_runs', 'automation_runs.event_id', '=', 'automation_events.id')
            // Grouped and qualified. Ungrouped, `A or B and C` binds as
            // `A or (B and C)`, which quietly counts every run in the
            // organization rather than this cascade's — and `id` is ambiguous
            // once both tables are in the query.
            ->where(function ($q) use ($root) {
                $q->where('automation_events.root_event_id', $root)
                    ->orWhere('automation_events.id', $root);
            })
            ->sum('automation_runs.action_count');
    }
}
