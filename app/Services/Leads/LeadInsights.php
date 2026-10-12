<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Ai\GrokCli;
use App\Support\Ai\NameDecision;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Support\Facades\Log;
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
    public const BOOK    = 'BOOK';
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
     * @return array{classification: string, summary: string, name: ?string, name_basis: string, zip: ?string, not_a_lead: bool, lost: bool, job_applicant: bool}|null
     *
     * @throws \RuntimeException when Grok fails, so the caller retries later
     */
    public function read(Lead $lead): ?array
    {
        $thread = $this->thread($lead);

        if ($thread === '' || ! self::grok()) {
            return null;
        }

        $json = GrokCli::ask(
            "You read enquiries for a home-services business (chimneys, fireplaces, garage doors, "
            ."inspections). Calls are machine transcripts and start with the business's own phone "
            ."menu (\"Thank you for calling A1 Chimney, press 1...\"): ignore that. Answer:\n"
            ."- classification: BOOK (wants work done, quoted or scheduled), SUPPORT (an existing "
            ."customer about a booking, invoice, follow-up or complaint), ARCHIVE (spam, wrong number, "
            ."a vendor selling something, a hang-up, or nothing to act on).\n"
            ."- summary: what the person wants, one plain sentence, at most 140 characters.\n"
            ."- name: the CUSTOMER's own name, as they gave it (keep accents, hyphens, apostrophes; "
            ."one name is fine), only from: the customer introducing themselves (\"My name is ...\", "
            ."\"This is ...\"), the customer's answer when asked their name, a \"Name:\" line they "
            ."wrote, or the customer spelling or correcting it (use the spelled form). NEVER a "
            ."dispatcher, technician, the phone menu, the business, a review author, or someone "
            ."the customer mentions (\"my husband Mark\", \"ask for Andy\"); never made from an "
            ."email handle. Null if not given.\n"
            ."- name_basis: how the name was given: self_introduction, answer_to_name_question, "
            ."spelled_or_corrected, or none when name is null.\n"
            ."- zip: the 5-digit US ZIP code of the service address only if the customer says it; null otherwise.\n"
            ."- not_a_lead: true ONLY when this is not a customer at all: spam, a vendor or marketer selling "
            ."something, a robocall, or a wrong number. A real person asking about any "
            ."service, even one we may not offer, or who declined a price, is false.\n"
            ."- job_applicant: true when the person is asking for a job or work with us, not for a service.\n"
            ."- lost: true when a real customer will not book: they declined the price or said no, or "
            ."want a service a chimney/fireplace/garage-door company does not do. False otherwise."
            ."\n\n".$thread,
            [
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
                ],
                'required' => ['classification', 'summary', 'name', 'name_basis', 'zip', 'not_a_lead', 'lost', 'job_applicant'],
                'additionalProperties' => false,
            ],
        );

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
        ];
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
            'summary'        => mb_substr(trim((string) ($json['summary'] ?? '')), 0, 255),
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
                $json = GrokCli::ask(
                    self::REPLY_BRIEF."\n\n".$thread,
                    ['type' => 'object', 'properties' => ['reply' => ['type' => 'string']], 'required' => ['reply'], 'additionalProperties' => false],
                );
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
            // A call's words are its transcript, both sides in one text.
            $said = $row->text ?? $row->transcript;
            if ($said === null || trim($said) === '') {
                continue;
            }

            $who = $row->participant === LeadConversation::FROM_CONSUMER ? 'Customer' : 'Business';
            if ($row->text === null) {
                $who = $row->participant === LeadConversation::FROM_CONSUMER ? 'Call from the customer' : 'Call from the business';
            }
            $lines[] = "{$who}: ".trim((string) preg_replace('/\s+/', ' ', $said));
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
                'modelId'         => $this->model,
                'system'          => [['text' => $system]],
                'messages'        => [['role' => 'user', 'content' => [['text' => $user]]]],
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
        $end   = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return [];
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : [];
    }
}
