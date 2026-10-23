<?php

namespace App\Support\Social;

use App\Jobs\AnswerWhatsAppFromApp;
use App\Jobs\AnswerWhatsAppWithAi;
use App\Jobs\AnswerWhatsAppWithTech;
use App\Jobs\PlanReportCorrection;
use App\Jobs\PlanWhatsAppCommand;
use App\Jobs\ReadWhatsAppBill;
use App\Jobs\SendCallRecordings;
use App\Models\AssistantEntry;
use App\Models\Organization;
use App\Models\StaffBotItem;
use App\Models\StaffBotMenu;
use App\Models\User;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Support\Ai\AppAct;
use App\Support\Ai\AppAsk;
use App\Support\Ai\CallRecordingsRequest;
use App\Support\Ai\GrokCli;
use App\Support\Ai\ReportAsk;
use App\Support\Ai\ReportCorrection;
use App\Support\Assistant\AssistantBrain;
use App\Support\Assistant\TechAssistant;
use App\Support\Settings\SettingsResolver;
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

    /** A row of a role's own menu (AI Assistance › Staff bot, UI queue 88). */
    public const ITEM = 'i:';

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

        $yesOrNo = preg_match('/^(yes|y|si|sí|ok|confirm|no|n|cancel)$/iu', $text) === 1;
        $yes = preg_match('/^(no|n|cancel)$/i', $text) !== 1;

        // YES / NO to a bill photo held for this person (WhatsAppBills).
        if ($viewer !== null && $yesOrNo && WhatsAppBills::pending($viewer)) {
            $this->text($number, $to, app(WhatsAppBills::class)->answer($viewer, $yes), preview: false);

            return;
        }

        // YES / NO to a report correction held for this person (ReportCorrection).
        if ($viewer !== null && $yesOrNo && ($held = ReportCorrection::pending($viewer)) !== null) {
            $this->correctionAnswer($number, $to, $viewer, $held, $yes);

            return;
        }

        // YES / NO to a command held for this person (AppAct).
        if ($viewer !== null && $yesOrNo && ($held = AppAct::pending($viewer)) !== null) {
            $this->commandAnswer($number, $to, $viewer, $held, $yes);

            return;
        }

        $techMode = $viewer !== null && GrokCli::configured()
            && app(TechAssistant::class)->enabled(Organization::findOrFail($number->organization_id));

        // A photo from somebody who may file bills: read as a period's bills -
        // when its caption says bill or receipt, once the tech assistant is on
        // (Chimney Tech Assistant, 2026-10-23: everything else is a plate or valve).
        if ($viewer?->hasPermission(WhatsAppBills::PERMISSION) && $inbound->kind === 'image'
            && $inbound->media_id !== null && GrokCli::configured()
            && (! $techMode || preg_match('/\b(bills?|receipts?|recibo|factura)\b/iu', $text) === 1)) {
            ReadWhatsAppBill::dispatch($number->id, $to, $viewer->getKey(), (string) $inbound->media_id, $inbound->body);
            $this->text($number, $to, "\u{1F440} Reading the bills in that photo\u{2026}", preview: false);

            return;
        }

        // Staff photos (a rating plate, a valve label) go to the tech assistant.
        if ($techMode && $inbound->kind === 'image' && $inbound->media_id !== null) {
            AnswerWhatsAppWithTech::dispatch($number->id, $to, $viewer->getKey(), $text, (string) $inbound->media_id);
            $this->text($number, $to, "\u{1F440} Reading that photo\u{2026}", preview: false);

            return;
        }

        // No transcription yet: a voice note is asked to be typed.
        if ($viewer !== null && in_array($inbound->kind, ['audio', 'voice'], true)) {
            $this->text($number, $to, self::typeIt($this->lastWords($number, $to)), preview: false);

            return;
        }

        // A PDF is acknowledged; manuals are added in the app.
        if ($viewer !== null && $inbound->kind === 'document') {
            $this->text($number, $to, "\u{1F4C4} Got the file. Manuals are added in the app: AI Assistance \u{2192} Manuals, so every tech's answers can use them.", preview: false);

            return;
        }

        // "new job", or a parts / unit question, from staff: the tech assistant.
        if ($techMode && $text !== '' && TechAssistant::looksTechnical($text)) {
            AnswerWhatsAppWithTech::dispatch($number->id, $to, $viewer->getKey(), $text, null);

            return;
        }

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
                "\u{1F4CD} *Find a location*\n\n"
                ."Text a city or state and I'll send its phone, address and service area.\n\n"
                ."\u{1F4AC} For example *Chicago* or *NJ*");

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

        if (str_starts_with($choice, self::ITEM)) {
            $this->answerItem($number, $to, $viewer, substr($choice, strlen(self::ITEM)));

            return;
        }

        // A row id we do not know: a menu drawn before the Library replaced the
        // built-in answers. The fresh menu is a better answer than silence.
        $this->menu($number, $to, $viewer);
    }

    /**
     * A row of the sender's role menu. Asked about the sender on every tap, as
     * the rest is: a row tapped after their role changed, or one from another
     * role's menu, is refused like any staff-only answer. A Library row is
     * read through AssistantBrain, so it keeps its own audience and place.
     */
    private function answerItem(WhatsAppNumber $number, string $to, ?User $viewer, string $id): void
    {
        $item = StaffBotItem::query()->with('menu', 'guide')->find($id);

        if ($item === null || $viewer === null || ! $item->menu->isFor($viewer->role_id)) {
            $this->refuse($number, $to);

            return;
        }

        if ($item->source === StaffBotItem::SOURCE_LIBRARY) {
            $entry = $item->assistant_entry_id === null ? null : $this->brain->entry($viewer, $item->assistant_entry_id);
            $answer = trim((string) $entry?->body) === '' ? null : (string) $entry->body;
        } else {
            $answer = $item->answer();
        }

        // The house voice (GrokCli::WHATSAPP_STYLE): a bold title with one
        // emoji, then the text. A Library entry and Reports' line are written
        // in that voice already and go as they are. Linked content not written
        // yet says so rather than going quiet.
        $this->text($number, $to, mb_substr(match (true) {
            $answer === null => "\u{1F6A7} *{$item->title}*\n\u{23F3} Coming soon. Ask your manager in the meantime.\n\n\u{1F4CB} Text *MENU* to go back.",
            $item->source === StaffBotItem::SOURCE_GUIDE => "\u{1F4D8} *{$item->title}*\n\n".self::look($answer),
            default => self::look($answer),
        }, 0, AssistantEntry::MAX_BODY));

        // A guide's pictures follow the text, each its own message (Meta:
        // one media a message, fetched from the link when sent).
        if ($answer !== null) {
            foreach ($item->guideImages() as $image) {
                $this->cloud->replyFreeForm($number, $to, [
                    'type' => 'image',
                    'image' => ['link' => $image['link'], 'caption' => mb_substr($image['caption'], 0, 1024)],
                ], $image['caption']);
            }
        }
    }

    /**
     * The first menu, in the editor's order, that names the sender's role and
     * has a row in it; null for a stranger, or a role no menu names, who get
     * the Library's sections as before.
     */
    private function roleMenu(?User $viewer): ?StaffBotMenu
    {
        if ($viewer === null || $viewer->role_id === null) {
            return null;
        }

        return StaffBotMenu::query()->with('items')->orderBy('position')->orderBy('created_at')->get()
            ->first(fn (StaffBotMenu $m) => $m->isFor($viewer->role_id) && $m->items->isNotEmpty());
    }

    /**
     * A staff answer tapped by somebody who is not staff - or no longer is -
     * and an id that does not exist are answered alike, so neither tells a
     * stranger that something is being kept from them.
     */
    private function refuse(WhatsAppNumber $number, string $to): void
    {
        $this->text($number, $to, "\u{1F512} That option is for staff only.\n\n\u{1F4CB} Text *MENU* to see what is available.");
    }

    private function answer(WhatsAppNumber $number, string $to, AssistantEntry $entry): void
    {
        // No link card: one would sit above the answer and push it off screen.
        $this->text($number, $to, mb_substr(self::look($entry->body), 0, AssistantEntry::MAX_BODY), preview: false);
    }

    /** @param  Collection<int, AssistantEntry>  $entries */
    private function sectionList(WhatsAppNumber $number, string $to, Collection $entries): void
    {
        $section = (string) $entries->first()->section;

        $this->answerList($number, $to, $section, "\u{1F4DA} Choose a topic below.", $entries);
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
                        'title' => mb_substr(self::cap($section), 0, 24),
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
            ? $lead."\n\n\u{1F447} Choose an option below."
            : self::look($this->brain->welcome($organization)), 0, 1024);

        // The sender's role menu when one names their role (UI queue 88);
        // otherwise one row per Library section. Room left for Find a location.
        $roleMenu = $this->roleMenu($viewer);
        $library = $roleMenu !== null
            ? $roleMenu->items->take(self::MAX_ROWS - 1)->map(
                fn (StaffBotItem $item) => $this->row(self::ITEM.$item->id, $item->title, $item->line),
            )->values()->all()
            : $this->brain->sections($viewer)->take(self::MAX_ROWS - 1)->map(
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
                        ['title' => mb_substr($roleMenu?->name ?? 'Library', 0, 24), 'rows' => $library],
                        ['title' => 'Company', 'rows' => [
                            $this->row(self::FIND_LOCATION, 'Find a location', 'Phone, address, service area'),
                        ]],
                    ], fn (array $s) => $s['rows'] !== [])),
                ],
            ],
        ], $body);
    }

    /**
     * Jose, 2026-10-14: "Menu's use caps at the beginning of sentences and
     * emojis instead of dots, visual storytelling is always better." Applied
     * as the message goes out, so the Library keeps what people typed.
     */
    public static function look(string $text): string
    {
        $out = implode("\n", array_map(function (string $line) {
            if (preg_match('/^(\s*)[•\-\*·]\s+(.*)$/u', $line, $m) === 1) {
                return $m[1].self::emojiFor($m[2]).' '.self::cap($m[2]);
            }

            return self::cap($line);
        }, explode("\n", $text)));

        // A first line with no emoji of its own gets the one its words call for.
        return trim($out) === '' || preg_match('/^\s*\*?[\x{1F000}-\x{1FAFF}\x{2190}-\x{2BFF}]/u', $out) === 1
            ? $out : self::emojiFor((string) strtok($out, "\n")).' '.$out;
    }

    /** The first letter upper-cased; markup (*, _) before it is kept. */
    public static function cap(string $text): string
    {
        return preg_replace_callback('/^([\s*_]*)(\p{Ll})/u', fn ($m) => $m[1].mb_strtoupper($m[2]), $text) ?? $text;
    }

    /** One emoji that tells the line's story at a glance, by its words. */
    public static function emojiFor(string $text): string
    {
        // ponytail: keyword map; add a line when a new kind of topic shows the plain diamond.
        $map = [
            'inspect|scan|video' => "\u{1F50D}", 'level' => "\u{1F4CA}", 'flue|liner|draft' => "\u{1F32C}\u{FE0F}",
            'crown|brick|mortar|bond|chimney' => "\u{1F9F1}", 'roof|attic|basement|crawl' => "\u{1F3E0}",
            'financ|lender|price|pay|cost' => "\u{1F4B0}", 'nfpa|code|standard|law' => "\u{1F4DC}",
            'csia|exam|training|guide|course|education' => "\u{1F393}", 'location|branch|address|area' => "\u{1F4CD}",
            'phone|call' => "\u{1F4DE}", 'review|google' => "\u{2B50}", 'value|about|company|mission' => "\u{1F3E2}",
            'schedul|job|appointment' => "\u{1F5D3}\u{FE0F}", 'safe|fire|carbon|smoke' => "\u{1F9EF}",
            'tool|ladder|equipment' => "\u{1F9F0}", 'appliance|furnace|stove|insert' => "\u{1F525}",
            'report|client|customer' => "\u{1F4DD}", 'topic' => "\u{1F4DA}",
        ];
        foreach ($map as $words => $emoji) {
            if (preg_match('/'.$words.'/i', $text) === 1) {
                return $emoji;
            }
        }

        return "\u{1F539}";
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
            'title' => mb_substr(self::emojiFor($title).' '.self::cap($title), 0, AssistantEntry::MAX_TITLE),
            'description' => mb_substr(self::cap((string) $description), 0, AssistantEntry::MAX_DESCRIPTION),
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

        // "Send me all the calls with 9524572594" (Jose, 2026-10-13): Grok reads
        // the number and dates off the webhook; Reports access, as Performance.
        if ($viewer?->hasPermission('reports.view') && GrokCli::configured() && CallRecordingsRequest::looksLikeOne($query)) {
            SendCallRecordings::dispatch($number->id, $to, $query);

            return;
        }

        // "Leave out job 4512 and put CT first": a correction to the open report
        // draft, from somebody with reports.correct, held for a YES.
        if ($viewer?->hasPermission(ReportCorrection::PERMISSION) && GrokCli::configured()
            && ReportCorrection::looksLikeOne($query) && ReportCorrection::openBatch() !== null
            && app(SettingsResolver::class)->get('ai_assistance.report_corrections', Organization::findOrFail($number->organization_id))) {
            PlanReportCorrection::dispatch($number->id, $to, $viewer->getKey(), $query);

            return;
        }

        // "Publish the reply to the Smith review": a command, held for a YES.
        // ponytail: a verb list decides command vs question; add verbs as staff use them.
        if ($viewer?->hasPermission(AppAct::PERMISSION) && GrokCli::configured() && self::isCommand($query)) {
            PlanWhatsAppCommand::dispatch($number->id, $to, $viewer->getKey(), $query);

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
            $this->answerList($number, $to, 'Answers', "\u{1F50E} These answers match *".mb_substr($query, 0, 40).'*', $answers);

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
            $this->menu($number, $to, $viewer, "\u{1F937} Nothing matched *".mb_substr($query, 0, 40)."*\n\nNo answer and no location for that.");

            return;
        }

        $name = $this->brain->name($organization);
        $reply = $matches->take(self::MAX_LOCATIONS)
            ->map(fn (array $site) => self::describe($site, $name))
            ->implode("\n\n");

        if ($matches->count() > self::MAX_LOCATIONS) {
            $reply .= "\n\n\u{2139}\u{FE0F} _Showing ".self::MAX_LOCATIONS.' of '.$matches->count()
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
        $lines = ["\u{1F3E2} *".($site['company_name'] ?? $fallbackName).'*'];

        // One fact per line with its emoji: the voice GrokCli::WHATSAPP_STYLE
        // gives AI replies (Jose, 2026-10-13).
        foreach ([
            'phone' => "\u{1F4DE}",
            'address' => "\u{1F4CD}",
            'service_area' => "\u{1F5FA}\u{FE0F}",
            'maps_url' => "\u{1F517}",
        ] as $key => $emoji) {
            $value = trim((string) ($site[$key] ?? ''));

            if ($value !== '') {
                $lines[] = $emoji.' '.$value;
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
            $figures = app(ReportAsk::class)->answer($organization, $query);
            if ($figures !== null) {
                $this->text($number, $to, $figures."\n\n_From EzBizi Reports, written by AI._", preview: false);

                return;
            }
        }

        $left = min(60, 70 - (int) ceil(microtime(true) - $started));
        try {
            $answer = $left >= 10 ? $this->brain->aiAnswer($viewer, $organization, $query, $left, forWhatsApp: true) : null;
        } catch (\RuntimeException) {
            $answer = null;
        }

        if ($answer === null && $viewer !== null) {
            // Not in the Library: staff get the app's own data, read-only (AppAsk), in a job of its own.
            AnswerWhatsAppFromApp::dispatch($number->id, $to, $viewer->id, $query);

            return;
        }
        if ($answer === null) {
            $this->nothingMatched($number, $to, $viewer, $query);

            return;
        }

        $this->text($number, $to, $answer['reply']."\n\n_Answered by AI from the Library._", preview: false);
    }

    public static function isCommand(string $query): bool
    {
        return preg_match('/^\s*(please\s+)?(pause|resume|publish|post|reply|approve|schedule|reschedule|book|assign|mark|create|add|send|update|set|move|rebuild|sync|start|stop|enable|disable)\b/i', $query) === 1;
    }

    /** ReadWhatsAppBill's half: the lines read back, held for a YES. */
    public function billRead(WhatsAppNumber $number, string $to, User $viewer, string $mediaId, ?string $caption): void
    {
        $this->text($number, $to, app(WhatsAppBills::class)->read($viewer, $mediaId, $caption), preview: false);
    }

    /** PlanReportCorrection's half: repeat the changes back in our words, run nothing. */
    public function correctionPlan(WhatsAppNumber $number, string $to, User $viewer, string $query): void
    {
        try {
            $plan = ReportCorrection::plan($query);
        } catch (\RuntimeException) {
            $this->text($number, $to, "\u{26A0}\u{FE0F} I couldn't read that just now. Try again in a minute.", preview: false);

            return;
        }

        // Not a correction after all: answered like any other question.
        if ($plan === null) {
            $this->aiReply($number, $to, $viewer, $query);

            return;
        }

        if ($plan['changes'] === []) {
            $this->text($number, $to, "\u{1F914} ".$plan['question'], preview: false);

            return;
        }

        ReportCorrection::hold($viewer, $plan);
        $this->text($number, $to, "\u{270F}\u{FE0F} *Change the report draft:*\n\n".implode("\n", $plan['lines'])
            .($plan['question'] !== null ? "\n\n\u{1F914} Not included: ".$plan['question'] : '')
            ."\n\nReply *YES* to make the change and get a new copy, or *NO* to cancel. _Expires in 10 minutes._", preview: false);
    }

    private function correctionAnswer(WhatsAppNumber $number, string $to, User $viewer, array $held, bool $yes): void
    {
        if (! $yes) {
            ReportCorrection::drop($viewer);
            $this->text($number, $to, "\u{1F44D} Cancelled. The draft is unchanged.");

            return;
        }

        $result = ReportCorrection::run($viewer, $held);
        $this->text($number, $to, $result['ok']
            ? "\u{2705} *Done.* A new copy of the report is on its way; it needs *APPROVE* again."
            : "\u{274C} ".$result['message'], preview: false);
    }

    /** PlanWhatsAppCommand's half: repeat the command back, run nothing. */
    public function commandPlan(WhatsAppNumber $number, string $to, User $viewer, string $query): void
    {
        try {
            $plan = AppAct::plan($query);
        } catch (\RuntimeException) {
            $plan = null;
        }

        if ($plan === null) {
            $this->text($number, $to, "\u{1F937} I could not match that to something EzBizi does. Do it in the app, or say it another way.", preview: false);

            return;
        }

        AppAct::hold($viewer, $plan);
        // The model's summary is not trusted on its own: the exact call and
        // values that will run are shown under it, so a "pause" that is
        // really an "enable" is visible before the YES.
        $exact = $plan['method'].' '.$plan['path'].($plan['body'] === [] ? '' : "\n".json_encode($plan['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->text($number, $to, "\u{2753} *".$plan['summary']."*\n\n```".$exact."```\n\nReply *YES* to do it, or *NO* to cancel. _Expires in 10 minutes._", preview: false);
    }

    private function commandAnswer(WhatsAppNumber $number, string $to, User $viewer, array $held, bool $yes): void
    {
        if (! $yes) {
            AppAct::drop($viewer);
            $this->text($number, $to, "\u{1F44D} Cancelled. Nothing was changed.");

            return;
        }

        $result = AppAct::run($viewer, $held);

        $this->text($number, $to, $result['ok']
            ? "\u{2705} Done: ".$held['summary']
            : "\u{274C} Not done (".$result['status'].'). '.($result['message'] !== '' ? $result['message'] : 'Nothing was changed.'), preview: false);
    }

    /** AnswerWhatsAppFromApp's half: Grok reads the app as the asker, GET only. */
    public function appReply(WhatsAppNumber $number, string $to, User $viewer, string $query): void
    {
        try {
            $reply = AppAsk::answer($viewer, $query);
        } catch (\RuntimeException) {
            $reply = null;
        }

        // Nothing in the Library or the app: the tech assistant, when it is on.
        if ($reply === null && GrokCli::configured()
            && app(TechAssistant::class)->enabled(Organization::findOrFail($number->organization_id))) {
            $this->techReply($number, $to, $viewer, $query, null);

            return;
        }

        $reply === null
            ? $this->nothingMatched($number, $to, $viewer, $query)
            : $this->text($number, $to, $reply."\n\n_From EzBizi, read by AI with your access._", preview: false);
    }

    /** AnswerWhatsAppWithTech's half: the tech assistant's answer, a photo read when one came. */
    public function techReply(WhatsAppNumber $number, string $to, User $viewer, string $query, ?string $mediaId): void
    {
        $files = [];
        if ($mediaId !== null) {
            try {
                $file = $this->cloud->downloadMedia($mediaId, $number->phone_number_id);
            } catch (\RuntimeException $e) {
                $this->text($number, $to, "\u{274C} ".$e->getMessage(), preview: false);

                return;
            }
            $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic'][strtolower(trim(explode(';', $file['mime'])[0]))] ?? 'jpg';
            $files['photo.'.$ext] = $file['bytes'];
        }

        try {
            $answer = app(TechAssistant::class)->answer($viewer, Organization::findOrFail($number->organization_id), $query, 'whatsapp', $files, $files === [] ? 60 : 120);
        } catch (\RuntimeException) {
            $this->text($number, $to, "\u{26A0}\u{FE0F} The tech assistant could not answer just now. Try again in a minute.", preview: false);

            return;
        }

        if ($answer === null) {
            $this->nothingMatched($number, $to, $viewer, $query);

            return;
        }

        $this->text($number, $to, $answer['reply'], preview: false);
    }

    /** "Please type it", in the person's language when their last words show it. */
    public static function typeIt(string $lastWords): string
    {
        return match (TechAssistant::language($lastWords)) {
            'es' => "\u{1F3A4} Todavía no puedo escuchar notas de voz. Por favor escríbelo.",
            'he' => "\u{1F3A4} אני עדיין לא יכול לשמוע הודעות קוליות. בבקשה תכתוב את זה.",
            default => "\u{1F3A4} I can't listen to voice notes yet. Please type it.",
        };
    }

    private function lastWords(WhatsAppNumber $number, string $to): string
    {
        return (string) WhatsAppMessage::query()->withoutGlobalScopes()
            ->where('organization_id', $number->organization_id)->where('counterparty', $to)
            ->where('direction', 'in')->whereNotNull('body')->latest('created_at')->value('body');
    }

    private function nothingMatched(WhatsAppNumber $number, string $to, ?User $viewer, string $query): void
    {
        $this->menu($number, $to, $viewer, "\u{1F937} Nothing matched *".mb_substr($query, 0, 40)."*\n\nNo answer and no location for that.");
    }
}
