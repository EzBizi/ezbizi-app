<?php

namespace App\Support\Social;

use App\Jobs\AnswerWhatsAppWithAi;
use App\Models\AssistantEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Support\Ai\GrokCli;
use App\Support\Assistant\AssistantBrain;
use Illuminate\Support\Collection;

/**
 * The staff line: a tech texts it, it answers.
 *
 * ── Why it needs no templates ────────────────────────────────────
 *
 * Every message it sends is a REPLY to one the person sent seconds earlier, so
 * it is always inside WhatsApp's 24-hour window and may be free-form - menus
 * and text. Measured before this was written: an interactive list is
 * accepted as a structurally valid payload. A template is only needed to START
 * a conversation, and this never does.
 *
 * ── One number, named in config ──────────────────────────────────
 *
 * `handles()` answers only for `services.meta.whatsapp_bot_number_id`. Without
 * that guard every inbound message on every WhatsApp number would be answered
 * by a robot - including EzBizi's own line, where a person is expected. Unset,
 * it answers for nothing, which is the right way to fail.
 *
 * ── What it says is the business's, not this file's ──────────────
 *
 * Since 2026-09-24 every answer is a row in the AI Assistance Library, which
 * the business edits in the app - Jose: "a screen in the app where im able to
 * change the replies from the menu myself". This file only draws them the way
 * WhatsApp wants them. WHICH answers a sender may read is AssistantBrain's, so
 * the app and WhatsApp cannot disagree about it; this file's one part in that
 * is placing the sender - see staffMember().
 */
final class WhatsAppStaffBot
{
    /** Menu row ids. Kept short: WhatsApp caps a row id at 200 characters. */
    public const FIND_LOCATION = 'find_location';

    public const SECTION = 's:';

    public const ENTRY = 'e:';

    /** Words that mean "show me the menu" rather than "search for this". */
    private const MENU_WORDS = ['menu', 'hi', 'hello', 'hey', 'hola', 'start', 'help', '?'];

    /** More than this and the reply stops being readable on a phone. */
    private const MAX_LOCATIONS = 5;

    /** WhatsApp's cap on rows in one list, across all its sections. */
    private const MAX_ROWS = 10;

    public function __construct(
        private readonly WhatsAppCloud $cloud,
        private readonly AssistantBrain $brain,
        private readonly WhatsAppIntake $intake,
    ) {}

    public function handles(string $phoneNumberId): bool
    {
        $bot = (string) config('services.meta.whatsapp_bot_number_id');

        return $bot !== '' && hash_equals($bot, $phoneNumberId);
    }

    /**
     * Answer one inbound message.
     *
     * @param  string|null  $choice  the id of a menu row the person tapped,
     *                               or null when they typed something
     */
    public function respond(WhatsAppNumber $number, WhatsAppMessage $inbound, ?string $choice): void
    {
        $to = (string) $inbound->counterparty;

        // Asked on every message, never remembered. A menu sent while somebody
        // was staff stays in their chat after they are deactivated, and tapping
        // it later must not still hand over what the company pays each lender.
        $viewer = $this->staffMember($number, $to);

        // A stranger is first asked whether they want a service or a job.
        if ($viewer === null && $this->intake->handle($number, $inbound, $choice)) {
            return;
        }

        if ($choice !== null) {
            $this->answerChoice($number, $to, $viewer, $choice);

            return;
        }

        $text = trim((string) $inbound->body);

        // An image, a voice note or a sticker arrives with no words; the
        // menu is the useful answer to all of them.
        if ($text === '' || in_array(mb_strtolower($text), self::MENU_WORDS, true)) {
            $this->menu($number, $to, $viewer);

            return;
        }

        $this->answerText($number, $to, $viewer, $text);
    }

    private function answerChoice(WhatsAppNumber $number, string $to, ?User $viewer, string $choice): void
    {
        if ($choice === self::FIND_LOCATION) {
            $this->text($number, $to,
                'Text a city or state and I\'ll send that location\'s phone, address and '
                .'service area - for example *Chicago* or *NJ*.');

            return;
        }

        if (str_starts_with($choice, self::SECTION)) {
            $entries = $this->brain->sections($viewer)->get(substr($choice, strlen(self::SECTION)));

            match (true) {
                $entries === null => $this->refuse($number, $to),
                $entries->count() === 1 => $this->answer($number, $to, $entries->first()),
                default => $this->sectionList($number, $to, $entries),
            };

            return;
        }

        if (str_starts_with($choice, self::ENTRY)) {
            $entry = $this->brain->entry($viewer, substr($choice, strlen(self::ENTRY)));

            $entry === null ? $this->refuse($number, $to) : $this->answer($number, $to, $entry);

            return;
        }

        // A row id we do not know: a menu drawn before the Library replaced the
        // built-in answers. The fresh menu is a better answer than silence.
        $this->menu($number, $to, $viewer);
    }

    /**
     * A staff answer tapped by somebody who is not staff - or no longer is -
     * and an id that does not exist are answered alike, so neither tells a
     * stranger that something is being kept from them.
     */
    private function refuse(WhatsAppNumber $number, string $to): void
    {
        $this->text($number, $to, 'That option is for staff only. Text MENU to see what is available.');
    }

    private function answer(WhatsAppNumber $number, string $to, AssistantEntry $entry): void
    {
        // No link card: one would sit above the answer and push it off screen.
        $this->text($number, $to, mb_substr($entry->body, 0, AssistantEntry::MAX_BODY), preview: false);
    }

    /** @param  Collection<int, AssistantEntry>  $entries */
    private function sectionList(WhatsAppNumber $number, string $to, Collection $entries): void
    {
        $section = (string) $entries->first()->section;

        $this->answerList($number, $to, $section, 'Choose a topic.', $entries);
    }

    /** @param  Collection<int, AssistantEntry>  $entries */
    private function answerList(WhatsAppNumber $number, string $to, string $section, string $body, Collection $entries): void
    {

        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => mb_substr($section, 0, 60)],
                'body' => ['text' => $body],
                'footer' => ['text' => 'Text MENU to go back'],
                'action' => [
                    'button' => 'Topics',
                    'sections' => [[
                        'title' => mb_substr($section, 0, 24),
                        // ponytail: WhatsApp shows 10 rows; an 11th answer in one
                        // section is reachable only in the app. The Library says so.
                        'rows' => $entries->take(self::MAX_ROWS)->map(fn (AssistantEntry $e) => $this->row(
                            self::ENTRY.$e->id, $e->title, $e->description,
                        ))->values()->all(),
                    ]],
                ],
            ],
        ], $body);
    }

    /**
     * @param  string|null  $lead  a line above the menu, e.g. why it is being shown
     */
    private function menu(WhatsAppNumber $number, string $to, ?User $viewer, ?string $lead = null): void
    {
        $organization = Organization::findOrFail($number->organization_id);

        /*
         * With a lead line - "No location matched" - the welcome is dropped:
         * greeting somebody whose search just failed reads as the bot not
         * having noticed. The short prompt carries on from the explanation.
         */
        $body = mb_substr($lead !== null
            ? $lead."\n\nPlease choose an option below."
            : $this->brain->welcome($organization), 0, 1024);

        // One row per section, leaving room for Find a location.
        $library = $this->brain->sections($viewer)->take(self::MAX_ROWS - 1)->map(
            fn (Collection $entries, string $section) => $this->row(
                self::SECTION.$section,
                $section,
                $entries->count() === 1 ? $entries->first()->description : $entries->count().' topics',
            ),
        )->values()->all();

        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => mb_substr($this->brain->name($organization), 0, 60)],
                'body' => ['text' => $body],
                'footer' => ['text' => 'Ask a question, or text MENU'],
                'action' => [
                    'button' => 'Choose',
                    // A section with no rows makes Meta refuse the whole list.
                    'sections' => array_values(array_filter([
                        ['title' => 'Library', 'rows' => $library],
                        ['title' => 'Company', 'rows' => [
                            $this->row(self::FIND_LOCATION, 'Find a location', 'Phone, address, service area'),
                        ]],
                    ], fn (array $s) => $s['rows'] !== [])),
                ],
            ],
        ], $body);
    }

    /**
     * A list row, cut to WhatsApp's lengths. Meta refuses the WHOLE list for
     * one row over them, which a tech experiences as no reply at all.
     *
     * @return array<string, string>
     */
    private function row(string $id, string $title, ?string $description): array
    {
        return array_filter([
            'id' => $id,
            'title' => mb_substr($title, 0, AssistantEntry::MAX_TITLE),
            'description' => mb_substr((string) $description, 0, AssistantEntry::MAX_DESCRIPTION),
        ], fn (string $v) => $v !== '');
    }

    /**
     * Who is writing, if they work here.
     *
     * An ACTIVE user of this number's organization whose phone, in EzBizi, is
     * the number writing in. Deliberately the people list the business already
     * keeps, rather than a list for the bot: add a tech's phone to their
     * profile and they are in, deactivate them and they are out, and nobody has
     * a second list to remember.
     *
     * ── It fails CLOSED, in three places ──────────────────────────
     *
     *   - A deactivated user is not staff. Their phone stays on the record, and
     *     a former employee must not keep reading what A1 pays each lender.
     *   - A user with no phone on file is not staff. Measured 2026-09-24: 8 of
     *     A-1's 12 users have one. The other four see the public menu until
     *     their phone is added - the safe direction.
     *   - A number that is not a real phone is not staff.
     *
     * Both sides go through the same E.164 normaliser, so "(305) 724-2199" in
     * a profile matches the "13057242199" WhatsApp sends.
     */
    private function staffMember(WhatsAppNumber $number, string $sender): ?User
    {
        /*
         * THE SENDER IS NOT A NUMBER SOMEBODY TYPED - it is a WhatsApp wa_id,
         * which ALWAYS carries its country code. Found by the deploy desk and
         * measured: e164() adds a 1 to any ten digits, which is right for a
         * profile ("469-555-1234") and wrong here, where ten digits means a
         * FOREIGN number. A 10-digit wa_id such as "4695551234" came out as
         * +14695551234 - identical to a staff member on a Dallas 469 line - so
         * whoever owned that foreign number read what A1 pays each lender.
         *
         * So the sender is accepted only as what a US or Canadian wa_id is:
         * eleven digits beginning with 1. The profile side keeps e164()'s rule,
         * because a profile IS typed by a person.
         */
        $digits = preg_replace('/\D+/', '', $sender) ?? '';

        if (strlen($digits) !== 11 || ! str_starts_with($digits, '1')) {
            return null;
        }

        $wanted = ThumbtackAssociatePhoneNumbers::e164($digits);

        if ($wanted === null) {
            return null;
        }

        return User::query()
            ->where('organization_id', $number->organization_id)
            ->where('is_active', true)
            ->whereNotNull('phone')
            ->get()
            ->first(fn (User $user) => ThumbtackAssociatePhoneNumbers::e164((string) $user->phone) === $wanted);
    }

    /**
     * Something typed: an answer from the Library if one fits, else a place.
     *
     * The Library goes first because a question is words and a place is a
     * name. Three characters or fewer is a branch code or a state ("CLE",
     * "NJ"), which only the location search understands.
     */
    private function answerText(WhatsAppNumber $number, string $to, ?User $viewer, string $query): void
    {
        // One or two letters match nearly every site, which reads as the bot
        // dumping its whole list rather than answering.
        if (mb_strlen($query) < 2) {
            $this->menu($number, $to, $viewer);

            return;
        }

        // A question about the figures goes to AI before the Library's word
        // match, which answered "how much did we sell" with the CSIA entry.
        if ($viewer?->hasPermission('reports.view') && GrokCli::configured() && self::asksFigures($query)) {
            AnswerWhatsAppWithAi::dispatch($number->id, $to, $viewer->getKey(), $query);

            return;
        }

        $answers = mb_strlen($query) > 3 ? $this->brain->search($viewer, $query) : collect();

        if ($answers->count() === 1) {
            $this->answer($number, $to, $answers->first());

            return;
        }

        if ($answers->isNotEmpty()) {
            $this->answerList($number, $to, 'Answers', 'These answers match "'.mb_substr($query, 0, 40).'".', $answers);

            return;
        }

        $organization = Organization::findOrFail($number->organization_id);
        $matches = $this->brain->locations($organization, $query);

        if ($matches->isEmpty()) {
            // Grok reads the Library in the background: it takes seconds, and
            // the webhook must answer Meta at once (Jose, 2026-10-12).
            // Staff only: strangers have the intake, and each ask is an xAI call.
            if ($viewer !== null && GrokCli::configured() && mb_strlen($query) > 3) {
                AnswerWhatsAppWithAi::dispatch($number->id, $to, $viewer?->getKey(), $query);

                return;
            }
            $this->menu($number, $to, $viewer, 'Nothing matched "'.mb_substr($query, 0, 40).'" - no answer and no location.');

            return;
        }

        $name = $this->brain->name($organization);
        $reply = $matches->take(self::MAX_LOCATIONS)
            ->map(fn (array $site) => self::describe($site, $name))
            ->implode("\n\n");

        if ($matches->count() > self::MAX_LOCATIONS) {
            $reply .= "\n\n_Showing ".self::MAX_LOCATIONS.' of '.$matches->count()
                .'. Text a city for fewer._';
        }

        $this->text($number, $to, $reply);
    }

    /**
     * Sounds like the business's figures. ponytail: a word list; AI still
     * decides (ReportAsk), and a miss falls back to the Library's AI answer.
     */
    public static function asksFigures(string $query): bool
    {
        return preg_match('/\b(sales?|sell|sold|revenue|gross|profit|jobs?|leads?|deposits?|cancel\w*|how (much|many)|numbers|report|last (week|month)|this (week|month)|yesterday|today)\b/i', $query) === 1;
    }

    /** @param  array<string, string>  $site */
    private static function describe(array $site, string $fallbackName): string
    {
        $lines = ['*'.($site['company_name'] ?? $fallbackName).'*'];

        foreach ([
            'phone' => 'Phone',
            'address' => 'Address',
            'service_area' => 'Serves',
            'maps_url' => 'Map',
        ] as $key => $label) {
            $value = trim((string) ($site[$key] ?? ''));

            if ($value !== '') {
                $lines[] = $label.': '.$value;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  bool  $preview  draw a card for a link in the body
     */
    private function text(WhatsAppNumber $number, string $to, string $body, bool $preview = true): void
    {
        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'text',
            'text' => ['preview_url' => $preview, 'body' => $body],
        ], $body);
    }

    /** AnswerWhatsAppWithAi's half: Grok's answer, or the usual "nothing matched" menu. */
    public function aiReply(WhatsAppNumber $number, string $to, ?User $viewer, string $query): void
    {
        $organization = Organization::findOrFail($number->organization_id);

        // A question about the figures, from someone who may read Reports
        // (Jose, 2026-10-13, Rodrigo's report-on-request). The whole reply
        // fits the job's 75 s (AnswerWhatsAppWithAi): the Library gets what
        // the figures left, and nothing when under 10 s remain.
        $started = microtime(true);
        if ($viewer?->hasPermission('reports.view')) {
            $figures = app(\App\Support\Ai\ReportAsk::class)->answer($organization, $query);
            if ($figures !== null) {
                $this->text($number, $to, $figures."\n\n_From EzBizi Reports, written by AI._", preview: false);

                return;
            }
        }

        $left = min(60, 70 - (int) ceil(microtime(true) - $started));
        try {
            $answer = $left >= 10 ? $this->brain->aiAnswer($viewer, $organization, $query, $left) : null;
        } catch (\RuntimeException) {
            $answer = null;
        }

        if ($answer === null) {
            $this->menu($number, $to, $viewer, 'Nothing matched "'.mb_substr($query, 0, 40).'" - no answer and no location.');

            return;
        }

        $this->text($number, $to, $answer['reply']."\n\n_Answered by AI from the Library._", preview: false);
    }
}
