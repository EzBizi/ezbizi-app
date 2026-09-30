<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An enquiry that arrived through a connected advertising account.
 *
 * A member of the public left their phone number expecting to be called back
 * about work. That is what every field here is in service of. Nothing on this
 * model sends anything; a reply goes out because a person sent it, or because
 * a rule the organization wrote and switched on did (the auto reply and the
 * one follow-up, since 2026-09-29 - see LeadReplyChannel).
 */
class Lead extends Model implements CarriesAutomationScope, HoldsPersonalData, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    /** Google's Local Services, through the tenant's own Google Ads account. */
    public const SOURCE_LOCAL_SERVICES = 'local_services';

    /**
     * Thumbtack, since 2026-09-08.
     *
     * A second source, and the column was always a string rather than an
     * enum so this needs no migration. The unique key is
     * (organization_id, source, remote_id), so a Thumbtack negotiation id
     * can never collide with a Google lead id that happens to match.
     */
    public const SOURCE_THUMBTACK = 'thumbtack';

    /**
     * The customer's own website, through the EzBizi WordPress plugin.
     *
     * Two sources rather than one because they behave differently on the
     * screen: a chat is a conversation somebody may still be waiting in, a form
     * is one message that has already been said in full. Both are written by
     * App\Support\Web\WebsiteEnquiries; neither needs a migration, for the
     * reason given above.
     */
    public const SOURCE_WEBSITE_CHAT = 'website_chat';

    public const SOURCE_WEBSITE_FORM = 'website_form';

    /**
     * A message sent to the business's Facebook Page, and to its Instagram.
     *
     * Two sources rather than one, for the same reason chat and form are two:
     * they are different inboxes to the person answering. A Page message and an
     * Instagram DM arrive from different audiences, are answered in different
     * tones, and a business that has one may not have the other - so a single
     * "social" chip would hide which of the two somebody is actually reading.
     *
     * They arrive THROUGH a connected account, so both are in
     * SOURCES_VIA_CONNECTION: losing the connection row is a fact about the
     * enquiries and not only about the account.
     */
    public const SOURCE_FACEBOOK = 'facebook';

    public const SOURCE_INSTAGRAM = 'instagram';

    /**
     * Workiz, since 2026-09-23 - A-1's own book, migrating off A1 Tools.
     *
     * The odd one out, and the difference drives everything below: a Workiz
     * lead is a record somebody TYPED INTO A CRM, not a message a member of the
     * public sent us. There is no thread, no recording and no channel to answer
     * on - EzBizi holds read-only v1 tokens by Jose's ruling of 2026-09-14.
     *
     * Two consequences are implemented rather than described: it registers no
     * LeadReplyChannel, and it is absent from INBOX_SOURCES below - it is a
     * book of past work, not an inbox anybody owes an answer to.
     */
    public const SOURCE_WORKIZ = 'workiz';

    /**
     * The sources that arrive THROUGH a connected account.
     *
     * Not a list of "external" sources: the website is external too. It is the
     * list of sources where a `social_connections` row is what makes the
     * enquiries arrive at all, which is why losing that row is a fact about
     * the enquiries rather than only about the account.
     *
     * Website chat and forms are deliberately absent. They come in through the
     * WordPress plugin and never carry a `connection_id` — they are born with
     * the column null, so a rule written as "hide every lead with no
     * connection" would hide every website enquiry the day it shipped.
     */
    public const SOURCES_VIA_CONNECTION = [
        self::SOURCE_LOCAL_SERVICES,
        self::SOURCE_THUMBTACK,
        self::SOURCE_FACEBOOK,
        self::SOURCE_INSTAGRAM,
        // Workiz too: the connection is one branch's API token, and an account
        // nobody is watching should not keep showing its book. Its connections
        // are always franchise-scoped - WorkizConnectController refuses anything
        // else, pinned by WorkizConnectTest::test_it_refuses_outside_a_branch_view.
        self::SOURCE_WORKIZ,
    ];

    /**
     * The sources that are an INBOX - a live channel a member of the public
     * used, where somebody here still owes a first answer.
     *
     * == What this is for ==
     *
     * The Unreplied filter is `last_contact_from = CONSUMER OR IS NULL`, and
     * the null half is deliberate: a Google enquiry whose conversation has not
     * been fetched yet has nobody waiting on it in either reading.
     *
     * A Workiz lead is permanently null there, because it has no conversation
     * rows at all and never will. Left in, A-1's thirty-one thousand Workiz
     * rows land in the dispatcher's morning queue and stay - the one list whose
     * entire value is that everything in it needs answering.
     *
     * == The discriminator is NOT "can we reply to it" ==
     *
     * That was the first rule written here and a test disproved it in a
     * sentence: `website_form` has no reply channel either - you ring a form
     * submission back, you do not answer it in EzBizi - and a website form is
     * exactly the thing that SHOULD be in the queue. Had the wrong rule
     * shipped, every website enquiry would have quietly left the dispatcher's
     * morning list.
     *
     * The real difference is what the row IS. Four of these are enquiries
     * somebody sent us and nobody has answered yet. Workiz is a book of records
     * copied from another system, most of them jobs finished months ago. One is
     * an inbox; the other is history.
     */
    public const INBOX_SOURCES = [
        self::SOURCE_LOCAL_SERVICES,
        self::SOURCE_THUMBTACK,
        self::SOURCE_WEBSITE_CHAT,
        self::SOURCE_WEBSITE_FORM,
        self::SOURCE_FACEBOOK,
        self::SOURCE_INSTAGRAM,
    ];

    /**
     * Enquiries a person should still be shown — those whose account is connected.
     *
     * Jose, 2026-09-16, after disconnecting A-1 Chimney's Google Ads account
     * and finding its enquiries still listed: *"if the account where the leads
     * come from is disconnected, then it should hide said leads, only show them
     * again once the account is reconnected."*
     *
     * ── Hidden, not deleted, and not a global scope ──────────────
     *
     * Disconnecting nulls `leads.connection_id`; it does not touch the rows.
     * They keep `remote_customer_id`, which is what lets `reclaim()` re-adopt
     * them the moment the same account is connected again — so "show them again
     * once the account is reconnected" needs no second mechanism, it is the
     * adoption that already exists.
     *
     * A GLOBAL scope would have been fewer call sites and the wrong shape: it
     * would also hide these rows from retention sweeps, exports, automations
     * and the reply path, which is not hiding, it is a silent partial delete.
     * This is applied on the two surfaces a person LOOKS at — the list and the
     * dashboard count — and nowhere else, so the figures a person compares
     * agree with each other and nothing downstream loses a record.
     *
     * Reporting is deliberately left alone: what a business spent on enquiries
     * last quarter did not stop being true because the account was disconnected
     * this morning.
     */
    /**
     * Where this enquiry sits in the business, for automations and notifications.
     *
     * ── What it fixes ───────────────────────────────────────────
     *
     * Without this, `NotificationDispatcher` falls through to sniffing
     * `franchise_id` off the subject, finds null on a website chat, and takes
     * the "nothing to scope by" branch - which notifies EVERY ACTIVE USER.
     * Measured on production by Automations, 2026-09-16: all five website_chat
     * leads carry a company and no franchise, so Jose's live "Live Chat Lead
     * Warning" rule was telling all twelve people in the business about each
     * one. Nothing was broken; it was the documented fallback being reached by
     * a record shape the sniff was not written for.
     *
     * ── Three answers, not one ──────────────────────────────────
     *
     * The suggestion that came with the measurement was to return the company
     * unconditionally. That is right for a website enquiry and wrong for the
     * others: a lead from an ORGANIZATION-scoped connection carries company and
     * franchise both null - which is every row from Thumbtack's twelve
     * businesses - so a blanket answer hands back `['company', null]`, a scope
     * type naming no id. The dispatcher would then ask "who can act on company
     * null", and the honest answer to that question is nobody.
     *
     * So: the franchise when it has one, the company when it has one, and
     * `[null, null]` when it has neither. The last case is not a shrug - the
     * dispatcher reads it as the organization and narrows to the people who
     * STAND at the organization rather than everybody in it, which is still
     * tighter than the everybody-active branch this replaces.
     *
     * @return array{0: string|null, 1: string|null}
     */
    public function automationScope(): array
    {
        if ($this->franchise_id !== null) {
            return [Assignment::SCOPE_FRANCHISE, $this->franchise_id];
        }

        if ($this->company_id !== null) {
            return [Assignment::SCOPE_COMPANY, $this->company_id];
        }

        return [null, null];
    }

    public function scopeSourceStillConnected(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNotNull($this->getTable().'.connection_id')
                ->orWhereNotIn($this->getTable().'.source', self::SOURCES_VIA_CONNECTION);
        });
    }

    /**
     * Every source, in the order the screen draws its chips.
     *
     * Total by construction: each value is granted separately as
     * `leads.<source>.view`, so a fifth source added without a fifth
     * TabDefinition would be invisible to everybody rather than visible to
     * everybody. That is the safe direction of the two - a source nobody can
     * see gets reported the day it arrives; a source everybody can see is the
     * failure nobody notices.
     */
    public const SOURCES = [
        self::SOURCE_LOCAL_SERVICES,
        self::SOURCE_THUMBTACK,
        self::SOURCE_WEBSITE_CHAT,
        self::SOURCE_WEBSITE_FORM,
        self::SOURCE_FACEBOOK,
        self::SOURCE_INSTAGRAM,
        self::SOURCE_WORKIZ,
    ];

    /**
     * Which sources this person may be shown - Jose, 2026-09-17: *"i should be
     * able to grant access by sub tab as well"*.
     *
     * An empty array is a real answer and means no leads, not all leads. A role
     * holding `leads.view` and none of the four is somebody who may open the
     * module and see nothing in it, which is odd but is what was granted; the
     * migration that ships with this hands every existing role all four, so
     * nobody arrives at that state by accident.
     *
     * @return list<string>
     */
    public static function sourcesVisibleTo(User $user): array
    {
        return array_values(array_filter(
            self::SOURCES,
            fn (string $source): bool => $user->hasPermission('leads.'.$source.'.view'),
        ));
    }

    /**
     * The same rule as a query, because the list is one endpoint.
     *
     * Leads has ONE list rather than a pane per source, so hiding a chip on the
     * screen would leave the rows in `All`. The gate has to be here, on every
     * request, with or without a `source` parameter.
     */
    public function scopeSourceVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereIn($this->getTable().'.source', self::sourcesVisibleTo($user));
    }

    /**
     * Ours, not the source's.
     *
     * `NEW` is the absence of a decision rather than a decision, which is why a
     * sync may set it and nothing else may set it back.
     */
    public const STATUS_NEW = 'new';

    public const STATUS_BOOKED = 'booked';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUS_NOT_A_LEAD = 'not_a_lead';

    /** @var string[] */
    public const STATUSES = [
        self::STATUS_NEW,
        self::STATUS_BOOKED,
        self::STATUS_ARCHIVED,
        self::STATUS_NOT_A_LEAD,
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'An enquiry a member of the public made to this organization through its '
            .'advertising — their name, phone number and what they asked about — together with '
            .'what the organization did with it. Held for as long as the organization keeps its '
            .'record of the work it was asked to do, and deleted with the organization. EzBizi '
            .'does not use it for anything other than showing it to that organization, and never '
            .'combines it with another organization\'s.'
        );
    }

    /**
     * §9. The person here never signed up for anything.
     *
     * Every other personal-data row on this platform is about somebody who
     * chose a relationship with the tenant — a customer, an employee, an
     * invited user. A lead is a member of the public who rang a phone number
     * they saw in an advert, and whose name and number are now in a database
     * they have never heard of. That is exactly the case §9 exists for, and the
     * reason this model must not be the one somebody forgets to declare.
     */
    public static function personalDataPurpose(): string
    {
        return 'Somebody who contacted this organization through its advertising, and what '
            .'the organization did about it. The contact details are what a person gave in '
            .'order to be called back about work; they are held so the organization can do '
            .'that and can account for what it was charged for.';
    }

    /** @return PersonalDataEntry[] */
    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('contact_name', PersonalDataEntry::IDENTITY,
                'Who to ask for when the business calls back.',
                Erasure::erase('Removed enquirer')),
            new PersonalDataEntry('phone, phone_digits, email', PersonalDataEntry::CONTACT,
                'How the business returns the enquiry. `phone_digits` is the same number '
                .'with the punctuation removed so a search matches however either side '
                .'wrote it — a second copy of the same personal data, and erased with it.',
                Erasure::erase()),
            new PersonalDataEntry('summary', PersonalDataEntry::CONTENT,
                'What the person said they wanted, in their own words or transcribed from '
                .'what they said. Free text, so it can contain anything they mentioned — an '
                .'address, a name, a reason for the work.',
                Erasure::erase()),
            new PersonalDataEntry('note', PersonalDataEntry::CONTENT,
                'What somebody at the business wrote down about this enquiry for their own '
                .'use. Free text about a person, written by somebody else.',
                Erasure::erase()),
            new PersonalDataEntry('note_by_user_id, note_updated_at', PersonalDataEntry::ACTIVITY,
                'Which member of staff wrote the note, and when - so a shared line can say '
                .'"Maya · 2h ago" and two people cannot overwrite each other unseen.',
                Erasure::erase()),
            new PersonalDataEntry('marketing_consent_at, marketing_consent_text', PersonalDataEntry::ACTIVITY,
                'Whether this person agreed to be sent marketing email, when, and the exact '
                .'words beside the box they ticked. Kept so the business can show what '
                .'they agreed to; erased with them, because a consent with nobody attached '
                .'to it is not a record of anything.',
                Erasure::erase()),
            new PersonalDataEntry('last_contact_preview', PersonalDataEntry::CONTENT,
                'The first line of the latest message on the enquiry, copied here so the list '
                .'can show it without a query per row. A copy of conversation text, erased '
                .'with it.',
                Erasure::erase()),
        ];
    }

    /**
     * Nothing joins a lead to a person on this platform.
     *
     * An enquirer is not a user, not a customer, and not an employee; there is
     * no id they share with any of them. A lead that later becomes a customer
     * gets a customer row of its own, and erasing that customer does not reach
     * back here — which is a gap worth stating rather than papering over with a
     * name match, because two people called J. Smith are not one person and a
     * purge that assumed otherwise would erase a stranger's record.
     */
    public static function personalDataAbout(Model $subject): ?Builder
    {
        // The enquirer is nobody on this platform (see above). A member of
        // staff IS, and the one place a lead names one is the note's author.
        return $subject instanceof User
            ? static::query()->where('note_by_user_id', $subject->getKey())
            : null;
    }

    /** Fractions written for received_at and last_contact_at (see LeadConversation). */
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = [
        'organization_id', 'company_id', 'franchise_id', 'connection_id', 'remote_customer_id', 'job_id', 'job_matched_by',
        'source', 'is_test', 'remote_id', 'contact_name', 'phone', 'email', 'phone_digits',
        'marketing_consent_at', 'marketing_consent_text',
        'lead_type', 'category', 'service', 'locale', 'summary', 'note', 'note_by_user_id', 'note_updated_at',
        'status', 'classification', 'classified_at', 'starred_at', 'source_status', 'charge_status', 'received_at', 'synced_at',
        'last_contact_at', 'last_contact_channel', 'last_contact_from', 'last_contact_preview',
    ];

    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'received_at' => 'datetime',
            'synced_at' => 'datetime',
            'last_contact_at' => 'datetime',
            'reply_overdue_raised_at' => 'datetime',
            'follow_up_raised_at' => 'datetime',
            'starred_at' => 'datetime',
            'note_updated_at' => 'datetime',
            'classified_at' => 'datetime',
            'marketing_consent_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(SocialConnection::class, 'connection_id');
    }

    /** Who last wrote the note. */
    public function noteBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'note_by_user_id');
    }

    /** The job this enquiry became, once somebody booked it. */
    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Every call and message on this enquiry, oldest first.
     *
     * Then by id, because "oldest first" is the whole contract of a thread
     * and a tie on occurred_at leaves the order to the engine: with rows
     * around them, the visitor's question came back after the business's
     * answer (UI/UX, 2026-09-08, found first in Web's endpoint and then here,
     * the original). The id is a UUID v7, time-ordered to the millisecond.
     * occurred_at now carries fractions too; the tiebreak stays for rows that
     * predate that and for two stamps that are genuinely equal.
     */
    /**
     * How long a customer's message may wait for an answer before the lead is
     * flagged and `lead.reply_overdue` raised (Jose, 2026-09-28: "they have
     * not replied to x customer in 10 mins").
     */
    public const REPLY_OVERDUE_AFTER_MINUTES = 10;

    /**
     * How long after the automatic first reply a quiet customer gets the one
     * follow-up (`lead.follow_up_due`) - three hours, as Rodrigo's bot did.
     */
    public const FOLLOW_UP_AFTER_HOURS = 3;

    /**
     * The customer spoke last, a reply is possible, nobody has answered for
     * REPLY_OVERDUE_AFTER_MINUTES, and the lead is still New - a booked,
     * archived or not-a-lead lead owes nothing ("not every response needs a
     * reply").
     */
    public function replyOverdue(bool $canReply): bool
    {
        return $canReply
            && $this->status === self::STATUS_NEW
            && $this->last_contact_from === LeadConversation::FROM_CONSUMER
            && $this->last_contact_at !== null
            && $this->last_contact_at->lte(now()->subMinutes(self::REPLY_OVERDUE_AFTER_MINUTES));
    }

    /** A Local Services call lead, and a text lead - A1 Leads' "LS Call" / "LS Text". */
    public const KIND_LS_CALL = 'ls_call';

    public const KIND_LS_TEXT = 'ls_text';

    /**
     * What kind of lead this is, as a rule narrows it: `ls_call`, `ls_text`
     * or `ls_booking` for Google Local Services (by lead_type), otherwise the
     * source itself - thumbtack, website_chat, website_form, facebook...
     */
    public function kind(): string
    {
        if ($this->source !== self::SOURCE_LOCAL_SERVICES) {
            return (string) $this->source;
        }

        return match ($this->lead_type) {
            'PHONE_CALL' => self::KIND_LS_CALL,
            'MESSAGE' => self::KIND_LS_TEXT,
            'BOOKING' => 'ls_booking',
            default => (string) $this->source,
        };
    }

    /**
     * `{{source_name}}` in a rule's message: the word a person reads, where
     * `{{source}}` is the wire value a condition compares (Jose, 2026-09-30: a
     * notification said "local_services"; "Local Services").
     */
    /** "Mark" from "Mark Morrow"; null for a phone number or an initial. */
    public static function firstNameOf(?string $name): ?string
    {
        $word = preg_replace("/[^A-Za-z'’-]/u", '', explode(' ', trim((string) $name))[0]);

        return mb_strlen($word) < 2 ? null : ucfirst(mb_strtolower($word));
    }

    public function getSourceNameAttribute(): string
    {
        return match ($this->source) {
            self::SOURCE_LOCAL_SERVICES => 'Local Services',
            self::SOURCE_WEBSITE_CHAT => 'Chat',
            self::SOURCE_WEBSITE_FORM => 'Form',
            self::SOURCE_FACEBOOK => 'Messenger',
            default => ucfirst((string) $this->source),
        };
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(LeadConversation::class)
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    /**
     * Digits only, so a search matches however either side wrote the number.
     *
     * `+1 (555) 123-4567` and `5551234567` are the same person. Kept as a
     * stored column rather than computed at query time because a `REPLACE`
     * chain in the WHERE clause cannot use an index, and the search this feeds
     * is the one somebody uses while a customer is on the phone.
     */
    public static function digits(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        return $digits === '' ? null : substr($digits, -32);
    }
}
