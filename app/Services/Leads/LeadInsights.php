<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Ai\AppAsk;
use App\Support\Ai\GrokCli;
use App\Support\Ai\NameDecision;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * What a model can say about an enquiry: which of three piles it belongs
 * on, one line of what it is about, and a draft the desk may send.
 *
 * Amazon Bedrock, Claude, through the AWS SDK this application already
 * carries for SES. Jose's decision of 2026-09-08 ("as you recommend"): the
 * managed service in the same AWS account, pay per request, nothing to run on
 * the box. Measured that day on A-1 Chimney's volume - twelve customer texts
 * and thirty-four leads a day - the bill is single digits a month.
 *
 * ── Two things this class never does ─────────────────────────────
 *
 * It never sends. A draft comes back as text the composer offers; a person
 * reads it, changes it, and presses Send - or does not. a1leads auto-replies
 * without a person; this platform's Lead model was written to forbid exactly
 * that, and the rule stands until Jose lifts it by name.
 *
 * It never runs without keys. `enabled()` is false until AWS credentials and
 * a Bedrock model id are in the environment, and everything that calls this
 * checks first, so the screen shows nothing rather than an error.
 */
class LeadInsights
{
    public const BOOK = 'BOOK';

    public const SUPPORT = 'SUPPORT';

    public const ARCHIVE = 'ARCHIVE';

    public const CLASSIFICATIONS = [self::BOOK, self::SUPPORT, self::ARCHIVE];

    private const REPLY_BRIEF = 'You write short text replies on behalf of a home-services business to a person '
        .'who enquired. Reply to the LAST message from the customer. Be warm, specific and brief: at most '
        .'three sentences, no greeting longer than their name, no sign-off, no promises of price or time '
        .'that the conversation does not already contain.';

    public function __construct(
        private readonly ?BedrockRuntimeClient $client,
        private readonly string $model,
    ) {}

    public function enabled(): bool
    {
        return $this->bedrock() || self::grok();
    }

    private function bedrock(): bool
    {
        return $this->client !== null && $this->model !== '';
    }

    /**
     * Grok through Jose's subscription (2026-10-09: "both ofc", suggested
     * replies and sorting, plus "fill in the gaps" on LS calls). Switched on
     * by LEADS_AI_GROK once /privacy/ names Leads text going to xAI.
     */
    private static function grok(): bool
    {
        return (bool) config('ezbizi.leads_ai_grok') && GrokCli::configured();
    }

    /**
     * Whether a lead may be sorted inline, as its thread is written. Bedrock
     * answers in about a second; Grok takes about eight, so on Grok the sorting
     * runs in ezbizi:leads-read instead, off the sync's back.
     */
    public function inline(): bool
    {
        return $this->bedrock();
    }

    /**
     * Grok's reading of a lead: the pile, one line, and what the customer said
     * about themselves that the source did not give us (Jose, 2026-10-09: "fill
     * in the gaps, like in the LS Calls, so we can get the names of the client
     * by reading the transcript or their zip code"). Null when nothing has
     * been said yet.
     *
     * @return array{classification: string, summary: string, name: ?string, name_basis: string, zip: ?string, not_a_lead: bool, lost: bool, job_applicant: bool, booked: bool, reason: string, google_credit: ?string}|null
     *
     * @throws RuntimeException when Grok fails, so the caller retries later
     */
    public function read(Lead $lead): ?array
    {
        $read = $this->readMany([$lead])[0];

        if ($read instanceof RuntimeException) {
            throw $read;
        }

        return $read;
    }

    /**
     * read() for several leads side by side (GrokCli::askMany, Jose's AI item
     * 4). A failure stays with its lead, which the caller leaves unread.
     *
     * @param  list<Lead>  $leads
     * @return list<array|RuntimeException|null> in order; null: nothing said yet, or Grok off
     */
    public function readMany(array $leads): array
    {
        $threads = array_map(fn (Lead $lead) => self::grok() ? $this->thread($lead) : '', $leads);
        $asked = array_filter($threads, fn ($t) => $t !== '');
        $answers = $asked === [] ? [] : array_combine(array_keys($asked),
            GrokCli::about(array_values(array_intersect_key($leads, $asked)), fn () => GrokCli::askMany(
                array_values(array_map(fn ($t, $i) => strtr(self::READ_PROMPT, ['{today}' => now()->format('D Y-m-d'), '{trades}' => GrokCli::trades($leads[$i]->organization_id), '{org}' => (string) $leads[$i]->organization?->name]).$t, $asked, array_keys($asked))), self::READ_SCHEMA)));

        return array_map(fn ($i) => match (true) {
            ! isset($answers[$i]) => null,
            $answers[$i] instanceof RuntimeException => $answers[$i],
            default => self::gate(self::parseRead($answers[$i]), $threads[$i]),
        }, array_keys($leads));
    }

    private const READ_PROMPT = 'You read enquiries for a home-services business ({trades}). '
        ."Calls are machine transcripts and start with the business's own phone "
        ."menu (\"Thank you for calling {org}, press 1...\"): ignore that. Answer:\n"
        .'- classification: BOOK (wants work done, quoted or scheduled), SUPPORT (an existing '
        .'customer about a booking, invoice, follow-up or complaint), ARCHIVE (spam, wrong number, '
        ."a vendor selling something, a hang-up, or nothing to act on).\n"
        ."- summary: what the person wants, one plain sentence, at most 140 characters.\n"
        ."- name: the CUSTOMER's own name, as they gave it (keep accents, hyphens, apostrophes; "
        .'one name is fine), only from: the customer introducing themselves ("My name is ...", '
        ."\"This is ...\"), the customer's answer when asked their name, a \"Name:\" line they "
        .'wrote, or the customer spelling or correcting it (use the spelled form). NEVER a '
        .'dispatcher, technician, the phone menu, the business, a review author, or someone '
        .'the customer mentions ("my husband Mark", "ask for Andy"); never made from an '
        ."email handle. Null if not given.\n"
        .'- name_basis: how the name was given: self_introduction, answer_to_name_question, '
        ."spelled_or_corrected, or none when name is null.\n"
        ."- zip: the 5-digit US ZIP code of the service address only if the customer says it; null otherwise.\n"
        .'- not_a_lead: true ONLY when this is not a customer at all: spam, a vendor or marketer selling '
        .'something, a robocall, or a wrong number. A real person asking about any '
        ."service, even one we may not offer, or who declined a price, is false.\n"
        ."- job_applicant: true when the person is asking for a job or work with us, not for a service.\n"
        .'- lost: true when a real customer will not book: they declined the price or said no, or '
        ."want a service a chimney/fireplace/garage-door company does not do. False otherwise.\n"
        .'- booked: true ONLY when the business and the customer agreed a specific day for the visit '
        .'("Thursday between 9 and 12") and the customer accepted it. A quote, a price, a callback, '
        ."or the customer saying they will check and call back is false.\n"
        .'- reason: one short sentence on why booked, lost, not_a_lead or job_applicant is true, '
        ."quoting what was said; empty when all are false.\n"
        .'- google_credit: whether Google should refund this lead: SPAM (a bot, a silent or dead call, '
        .'a robocall, a scam), SOLICITATION (someone selling to the business), JOB_TYPE_MISMATCH (asks '
        .'only for a service a chimney/fireplace/garage-door company does not do), or null. A real '
        .'customer who simply did not book is null.'."\n"
        // The reply reader (Jose, 2026-11-13: Rose Wyka's email gave her cell,
        // address and hours, and the lead picked up none of them).
        ."- phones / emails: every phone number and email the CUSTOMER gives as their own contact, as written; [] if none.\n"
        ."- address: the service address the customer gives (street, town, state, ZIP), else null.\n"
        .'- scope: the work asked for in one short phrase, keeping their corrections ("not a gas insert; '
        ."blower repaired or replaced, plus a cleaning\"), else null.\n"
        ."- deadline: a date the work must happen by, short (\"before Fri 11/20\"), else null. Today is {today}.\n"
        ."- availability: when they can be reached or visited, short (\"Mon/Wed/Fri 2-5, Thu 11:30-5; not Tue\"), else null."
        ."\n\n";

    /** Google's own SurveyDissatisfiedReason values AI may suggest for a credit. */
    public const CREDIT_REASONS = ['SPAM', 'SOLICITATION', 'JOB_TYPE_MISMATCH'];

    /**
     * Rodrigo's booking gate (a1leads booking-gate.js, deferral.js): a model
     * says "booked" on a call that ended "let me check with my wife and call
     * you back". The last words win. ponytail: a phrase list over the last
     * 400 characters; Rodrigo's speaker-aware version if the weekly AI check
     * shows people un-booking leads AI booked.
     */
    private const DEFERRALS = "/\\b(call (you|u) back|get back to you|check with my|talk (to|with) my|think about it|let me (check|think)|i'?ll (call|let you know)|not (sure|ready) yet)\\b/i";

    public static function deferred(string $thread): bool
    {
        return preg_match(self::DEFERRALS, mb_substr($thread, -400)) === 1;
    }

    /** A booking the customer put off at the end is not a booking. */
    private static function gate(array $read, string $thread): array
    {
        if ($read['booked'] && self::deferred($thread)) {
            $read['booked'] = false;
        }

        return $read;
    }

    private const READ_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'classification' => ['type' => 'string', 'enum' => self::CLASSIFICATIONS],
            'summary' => ['type' => 'string'],
            'name' => ['type' => ['string', 'null']],
            'name_basis' => ['type' => 'string', 'enum' => NameDecision::BASES],
            'zip' => ['type' => ['string', 'null']],
            'not_a_lead' => ['type' => 'boolean'],
            'lost' => ['type' => 'boolean'],
            'job_applicant' => ['type' => 'boolean'],
            'booked' => ['type' => 'boolean'],
            'reason' => ['type' => 'string'],
            'google_credit' => ['type' => ['string', 'null'], 'enum' => [...self::CREDIT_REASONS, null]],
            'phones' => ['type' => 'array', 'items' => ['type' => 'string']],
            'emails' => ['type' => 'array', 'items' => ['type' => 'string']],
            'address' => ['type' => ['string', 'null']],
            'scope' => ['type' => ['string', 'null']],
            'deadline' => ['type' => ['string', 'null']],
            'availability' => ['type' => ['string', 'null']],
        ],
        'required' => ['classification', 'summary', 'name', 'name_basis', 'zip', 'not_a_lead', 'lost', 'job_applicant', 'booked', 'reason', 'google_credit', 'phones', 'emails', 'address', 'scope', 'deadline', 'availability'],
        'additionalProperties' => false,
    ];

    /** @param  array<string, mixed>  $json */
    private static function parseRead(array $json): array
    {
        $class = strtoupper(trim((string) ($json['classification'] ?? '')));
        $name = trim((string) ($json['name'] ?? ''));
        $zip = trim((string) ($json['zip'] ?? ''));

        return [
            'classification' => in_array($class, self::CLASSIFICATIONS, true) ? $class : self::SUPPORT,
            'summary' => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 255),
            'name' => $name !== '' ? mb_substr($name, 0, 120) : null,
            'name_basis' => in_array($json['name_basis'] ?? null, NameDecision::BASES, true)
                ? $json['name_basis'] : ($name !== '' ? 'self_introduction' : 'none'),
            'zip' => preg_match('/^\d{5}$/', $zip) === 1 ? $zip : null,
            'not_a_lead' => ($json['not_a_lead'] ?? false) === true,
            'lost' => ($json['lost'] ?? false) === true,
            'job_applicant' => ($json['job_applicant'] ?? false) === true,
            'booked' => ($json['booked'] ?? false) === true,
            'reason' => mb_substr(trim((string) ($json['reason'] ?? '')), 0, 255),
            'google_credit' => in_array($json['google_credit'] ?? null, self::CREDIT_REASONS, true) ? $json['google_credit'] : null,
            // Ten digits, a leading US 1 dropped.
            'phones' => array_values(array_unique(array_filter(array_map(
                fn ($p) => substr((string) preg_replace('/^1(?=\d{10}$)/', '', (string) preg_replace('/\D/', '', (string) $p)), 0, 10),
                (array) ($json['phones'] ?? [])), fn ($d) => strlen($d) === 10))),
            'emails' => array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) ($json['emails'] ?? [])),
                fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false))),
            'address' => self::short($json['address'] ?? null, 255),
            'scope' => self::short($json['scope'] ?? null, 200),
            'deadline' => self::short($json['deadline'] ?? null, 60),
            'availability' => self::short($json['availability'] ?? null, 160),
        ];
    }

    private static function short(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' || strcasecmp($value, 'null') === 0 ? null : mb_substr($value, 0, $max);
    }

    /**
     * BOOK / SUPPORT / ARCHIVE and one line, or null when the lead has
     * nothing said yet, the service is off, or the model did not answer.
     *
     * @return array{classification: string, summary: string}|null
     */
    public function classify(Lead $lead): ?array
    {
        $thread = $this->thread($lead);

        if ($thread === '' || ! $this->enabled()) {
            return null;
        }

        $answer = $this->ask(
            'You sort enquiries for a home-services business (chimneys, fireplaces, '
            .'inspections). Read the conversation and answer with JSON only: '
            .'{"classification": "BOOK" | "SUPPORT" | "ARCHIVE", "summary": "<one line>"}. '
            .'BOOK: a person who wants work done, quoted or scheduled. '
            .'SUPPORT: an existing customer about a booking, an invoice, a follow-up, or a complaint. '
            .'ARCHIVE: spam, a wrong number, a vendor selling something, or nothing to act on. '
            .'The summary is what the person wants, in one plain sentence, no more than 140 characters.',
            $thread,
        );

        $json = self::json($answer);
        $classification = strtoupper(trim((string) ($json['classification'] ?? '')));

        if (! in_array($classification, self::CLASSIFICATIONS, true)) {
            return null;
        }

        return [
            'classification' => $classification,
            'summary' => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 255),
        ];
    }

    /** A draft for the desk to read, change and send. Null when there is nothing to draft from. */
    public function suggestReply(Lead $lead): ?string
    {
        $thread = $this->thread($lead);

        if ($thread === '' || ! $this->enabled()) {
            return null;
        }

        if (! $this->bedrock()) {
            try {
                $json = GrokCli::about($lead, fn () => GrokCli::ask(
                    self::REPLY_BRIEF."\n\n".$thread,
                    ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
                ));
            } catch (Throwable $e) {
                Log::warning('Grok did not answer for a lead.', ['reason' => mb_substr($e->getMessage(), 0, 200)]);

                return null;
            }
            $draft = trim((string) ($json['reply'] ?? ''));

            return $draft === '' ? null : mb_substr($draft, 0, 1000);
        }

        $answer = $this->ask(
            'You write short text replies on behalf of a home-services business to a person '
            .'who enquired. Reply to the LAST message from the customer. Be warm, specific and '
            .'brief: at most three sentences, no greeting longer than their name, no sign-off, '
            .'no promises of price or time that the conversation does not already contain. '
            .'Answer with the reply text only.',
            $thread,
        );

        $draft = trim($answer);

        return $draft === '' ? null : mb_substr($draft, 0, 1000);
    }

    /**
     * The draft with its clear spelling and grammar mistakes fixed, or null
     * when it has none (Jose, 2026-11-05: "Yes, I call you right away").
     * Never sent by itself: the composer offers it and a person taps it.
     */
    /** Seconds a proofread may take before the reply box gets nothing. */
    public const PROOFREAD_TIMEOUT = 20;

    public function proofread(?Lead $lead, string $text): ?string
    {
        $text = trim($text);

        if (mb_strlen($text) < 3 || ! $this->enabled()) {
            return null;
        }

        $brief = 'Fix only clear spelling and grammar mistakes in this text message from a '
            .'home-services business to a customer. Keep its words, tone, names, numbers, '
            .'emoji and line breaks; do not rephrase, shorten or add anything. If nothing '
            .'is clearly wrong, return it unchanged.';

        if (! $this->bedrock()) {
            try {
                $json = GrokCli::about($lead, fn () => GrokCli::ask(
                    $brief."\n\nText:\n".$text,
                    ['type' => 'object', 'properties' => ['fixed' => ['type' => 'string']], 'required' => ['fixed'], 'additionalProperties' => false],
                    // A person waits on the reply box: give up well inside
                    // nginx's gateway limit (11-10: 10 x 504 on the 300 s default).
                    self::PROOFREAD_TIMEOUT,
                ));
            } catch (Throwable $e) {
                Log::warning('Grok did not proofread a reply.', ['reason' => mb_substr($e->getMessage(), 0, 200)]);

                return null;
            }
            $fixed = trim((string) ($json['fixed'] ?? ''));
        } else {
            $fixed = trim($this->ask($brief.' Answer with the text only.', $text));
        }

        return $fixed === '' || $fixed === $text || mb_strlen($fixed) > 2 * mb_strlen($text) ? null : $fixed;
    }

    /** The conversation as the model reads it: who said what, oldest first, capped. */
    private function thread(Lead $lead): string
    {
        $lines = [];

        if (($lead->contact_name ?? '') !== '') {
            $lines[] = 'Enquirer: '.$lead->contact_name;
        }

        $rows = LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->orderBy('occurred_at')
            ->get();

        $saidSomething = false;

        foreach ($rows as $row) {
            // A call's words are its transcript, both sides in one text. Never
            // its text: on a RingCentral call that is our own tag ("Possible
            // spam"), which Grok read as the customer's words (2026-10-16).
            $isCall = $row->channel === LeadConversation::CHANNEL_PHONE_CALL;
            $said = $isCall ? $row->transcript : ($row->text ?? $row->transcript);
            if ($said === null || trim($said) === '') {
                continue;
            }

            $who = $row->participant === LeadConversation::FROM_CONSUMER ? 'Customer' : 'Business';
            if ($isCall || $row->text === null) {
                $who = $row->participant === LeadConversation::FROM_CONSUMER ? 'Call from the customer' : 'Call from the business';
            }
            // A question tapped from the ad's suggestions shows interest, not their own words.
            if ($row->is_ad_question) {
                $who = 'Customer tapped a suggested question from the ad';
            }
            // Jose, 2026-10-13: signed links never reach xAI.
            $lines[] = "{$who}: ".trim((string) preg_replace('/\s+/', ' ', AppAsk::withoutSignedLinks($said)));
            $saidSomething = $saidSomething || $row->participant === LeadConversation::FROM_CONSUMER;
        }

        if (! $saidSomething) {
            return '';
        }

        return mb_substr(implode("\n", $lines), -6000);
    }

    private function ask(string $system, string $user): string
    {
        try {
            $result = $this->client->converse([
                'modelId' => $this->model,
                'system' => [['text' => $system]],
                'messages' => [['role' => 'user', 'content' => [['text' => $user]]]],
                'inferenceConfig' => ['maxTokens' => 400, 'temperature' => 0.2],
            ]);
        } catch (Throwable $e) {
            // Nothing on screen depends on this answer; a quiet warning and a
            // null is the right failure. Never the thread text in the log.
            Log::warning('Bedrock did not answer for a lead.', ['reason' => mb_substr($e->getMessage(), 0, 200)]);

            return '';
        }

        $content = (array) ($result['output']['message']['content'] ?? []);

        foreach ($content as $block) {
            if (is_array($block) && isset($block['text'])) {
                return (string) $block['text'];
            }
        }

        return '';
    }

    /** @return array<string, mixed> */
    private static function json(string $text): array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text) ?? $text;

        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
