<?php

namespace App\Support\Social;

use App\Models\WebValue;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use Illuminate\Support\Collection;

/**
 * The A-1 Chimney staff line: a tech texts it, it answers.
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
 * ── Why nothing here checks who is asking - yet ──────────────────
 *
 * Everything it can say today is public: summaries of what NFPA 211 and CSIA
 * require, taken from their own published material, and the phone, address and
 * service area each location already publishes on its website (WebValue calls
 * itself "public by definition"). A staff check would guard nothing. It becomes necessary the moment a section carries something
 * that is NOT public - Zelle details, finance links - and it belongs beside
 * that section rather than in front of the whole bot, so the public half keeps
 * working for somebody not yet on the staff list.
 *
 * ── Locations are READ, never copied ─────────────────────────────
 *
 * From the sites Web Management already holds for the organization, at the
 * moment somebody asks. A list typed into this file would be right on the day
 * it was written and wrong the day a branch changed its number, and nobody
 * would find out until a tech called a dead line.
 */
final class WhatsAppStaffBot
{
    /** Menu row ids. Kept short: WhatsApp caps a row id at 200 characters. */
    public const NFPA_211 = 'nfpa_211';

    public const CSIA = 'csia';

    public const FIND_LOCATION = 'find_location';

    /**
     * What NFPA 211 and CSIA require, as the bot says it.
     *
     * ── Our words, sourced, and no links - Jose, 2026-09-24 ───────
     *
     * "I don't want for the bot to offer links, i want the bot to be able to
     * give out the information for the codes." NFPA 211 and CSIA's manual are
     * copyrighted and sold, so the TEXT cannot be repeated - but what a code
     * REQUIRES is a fact, and facts are not anybody's property. So each answer
     * states the requirement in plain words, as a training manual does.
     *
     * ── Nothing here is from memory ───────────────────────────────
     *
     * A wrong number from a fire-code bot is how a house burns. Every item was
     * taken from a published source on 2026-09-24:
     *   - annual inspection, and what it checks: NFPA 211 as CSIA quotes it
     *   - Table 15.2.1 decides the level; what a Level 1 is for: NFPA 211
     *   - Level 2 triggers and what it covers, flue sizing, the crown bond
     *     break: CSIA's own Level 2 SOP (Masonry Fireplace, rev. 4/5/19),
     *     which cites NFPA 211 for each
     *   - the CSIA exam basis and the monthly CEU quizzes: csia.org
     *
     * LEVEL 3 IS NAMED AND NOT DESCRIBED, because no source in hand describes
     * it. Its absence is honest; a plausible description would not be. Add it
     * when it is sourced, and name the source here.
     *
     * The certified people at A-1 should read these before techs rely on them.
     */
    private const CODE_TOPICS = [
        'nfpa_how_often' => [
            'title' => 'How often to inspect',
            'description' => 'The annual requirement',
            'body' => "*How often to inspect*\n\n"
                .'NFPA 211 requires chimneys, fireplaces and vents to be inspected at least '
                ."once a year.\n\n"
                ."Each inspection checks three things:\n"
                ."• the structure is sound\n"
                ."• it is free of deposits, such as creosote\n"
                .'• clearances to combustibles are correct',
        ],
        'nfpa_levels' => [
            'title' => 'Inspection levels',
            'description' => 'Level 1, 2 and 3',
            'body' => "*Inspection levels*\n\n"
                .'NFPA 211 defines three levels. Its Table 15.2.1 decides which one a job '
                ."needs.\n\n"
                .'*Level 1* - to confirm a chimney is still fit for continued use, under the '
                ."same conditions and with the same or a similar appliance.\n\n"
                .'*Level 2* - when conditions are changing or something may have caused '
                ."damage. See \"When a Level 2 is needed\".\n\n"
                .'*Level 3* - also defined by NFPA 211. Confirm its requirements against the '
                .'standard before relying on them.',
        ],
        'nfpa_level2_when' => [
            'title' => 'When a Level 2 is needed',
            'description' => 'The triggers',
            'body' => "*When a Level 2 inspection is needed*\n\n"
                ."Move up to a Level 2 when:\n"
                .'• a new appliance is added, or one is replaced with a different fuel, '
                ."efficiency or type\n"
                ."• a Level 1 can't settle whether the venting system is serviceable\n"
                ."• the property is being sold\n"
                ."• the chimney is going to be lined or relined\n"
                .'• there has been a building or chimney fire, a weather or seismic event, or '
                .'any malfunction likely to have damaged the chimney',
        ],
        'nfpa_level2_covers' => [
            'title' => 'What a Level 2 covers',
            'description' => 'Everything in a Level 1, plus more',
            'body' => "*What a Level 2 inspection covers*\n\n"
                ."Everything in a Level 1, plus:\n"
                .'• every accessible part of the fireplace, chimney and connected appliances '
                ."- tools and ladders may be needed\n"
                ."• the roof\n"
                ."• accessible attics, basements and crawl spaces\n"
                ."• every flue in the chimney, with its appliances\n"
                ."• a *video scan* of the chimney interior - this is REQUIRED\n\n"
                .'If you cannot scan it (flue size, offsets) or cannot reach an area, or the '
                .'client asks you not to, write that in the report with the reason.',
        ],
        'nfpa_flue_sizing' => [
            'title' => 'Flue sizing',
            'description' => 'Against the fireplace opening',
            'body' => "*Flue sizing for a fireplace*\n\n"
                ."NFPA 211 sizes the flue against the area of the fireplace opening:\n"
                ."• round flue - 1/12 of the opening area\n"
                ."• square or standard rectangular flue - 1/10\n"
                .'• rectangular flue with a 2:1 aspect ratio or more - 1/8',
        ],
        'nfpa_crown' => [
            'title' => 'Crown bond break',
            'description' => 'Where the top tile meets the crown',
            'body' => "*Crown bond break*\n\n"
                .'NFPA 211 requires a bond break where the top flue tile passes through the '
                .'crown, so the flue can expand upward without cracking the crown.',
        ],
    ];

    /** Appended to every code answer: what it is, and what it is not. */
    private const CODE_FOOTER = "\n\n_Our summary of NFPA 211 and CSIA guidance, in our own words. "
        .'Confirm against the current NFPA 211 edition adopted where you are working._';

    /** Words that mean "show me the menu" rather than "search for this". */
    private const MENU_WORDS = ['menu', 'hi', 'hello', 'hey', 'hola', 'start', 'help', '?'];

    /** More than this and the reply stops being readable on a phone. */
    private const MAX_LOCATIONS = 5;

    /** The `group` value Web Management gives every A1 Chimney site. */
    private const BRAND = 'A1 Chimney';

    public function __construct(private readonly WhatsAppCloud $cloud) {}

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

        if ($choice !== null) {
            $this->answerChoice($number, $to, $choice);

            return;
        }

        $text = trim((string) $inbound->body);

        // An image, a voice note or a sticker arrives with no words; the
        // menu is the useful answer to all of them.
        if ($text === '' || in_array(mb_strtolower($text), self::MENU_WORDS, true)) {
            $this->menu($number, $to);

            return;
        }

        $this->searchLocations($number, $to, $text);
    }

    private function answerChoice(WhatsAppNumber $number, string $to, string $choice): void
    {
        // A code topic: its answer, and the footer saying what it is.
        if (isset(self::CODE_TOPICS[$choice])) {
            $this->text($number, $to, self::CODE_TOPICS[$choice]['body'].self::CODE_FOOTER, preview: false);

            return;
        }

        match ($choice) {
            self::NFPA_211 => $this->codeTopics($number, $to),

            self::CSIA => $this->text($number, $to,
                "*CSIA - Chimney Safety Institute of America*\n\n"
                .'*Certification exam* - based on the Chimney & Venting Essentials manual and '
                ."NFPA 211.\n\n"
                .'*Continuing education* - CSIA runs free online Codes & Standards quizzes '
                .'during the first week of each month. Passing them earns CSIA CEUs.'
                .self::CODE_FOOTER, preview: false),

            self::FIND_LOCATION => $this->text($number, $to,
                'Text a city or state and I\'ll send that location\'s phone, address and '
                .'service area - for example *Chicago* or *NJ*.'),

            // A row id we do not know: an old menu tapped after a change here.
            // The fresh menu is a better answer than silence.
            default => $this->menu($number, $to),
        };
    }

    /** The NFPA 211 topics, as a second list under the main menu. */
    private function codeTopics(WhatsAppNumber $number, string $to): void
    {
        $body = 'Choose a topic. Each answer is a plain-English summary of what the code requires.';

        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => 'NFPA 211'],
                'body' => ['text' => $body],
                'footer' => ['text' => 'Text MENU to go back'],
                'action' => [
                    'button' => 'Topics',
                    'sections' => [[
                        // 24 characters at most, like a row title. The first
                        // draft was 28, and Meta refuses the whole list for it -
                        // which reaches the tech as no reply at all.
                        'title' => 'NFPA 211 topics',
                        'rows' => array_map(
                            fn (string $id, array $t) => [
                                'id' => $id,
                                'title' => $t['title'],
                                'description' => $t['description'],
                            ],
                            array_keys(self::CODE_TOPICS),
                            self::CODE_TOPICS,
                        ),
                    ]],
                ],
            ],
        ], $body);
    }

    /**
     * @param  string|null  $lead  a line above the menu, e.g. why it is being shown
     */
    private function menu(WhatsAppNumber $number, string $to, ?string $lead = null): void
    {
        /*
         * Jose, 2026-09-24, on the first real handset: "something more
         * professional, not just 'What do you need'."
         *
         * With a lead line - "No location matched" - the welcome is dropped:
         * greeting somebody whose search just failed reads as the bot not
         * having noticed. The short prompt carries on from the explanation.
         */
        $body = $lead !== null
            ? $lead."\n\nPlease choose an option below."
            : "Welcome to the A1 Chimney staff assistant.\n\n"
                .'Choose an option below for codes and standards, training resources, '
                .'or branch contact details.';

        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'header' => ['type' => 'text', 'text' => 'A1 Chimney Bot'],
                'body' => ['text' => $body],
                'footer' => ['text' => 'Text MENU any time'],
                'action' => [
                    'button' => 'Choose',
                    'sections' => [
                        ['title' => 'Codes & training', 'rows' => [
                            ['id' => self::NFPA_211, 'title' => 'NFPA 211', 'description' => 'The chimney standard'],
                            ['id' => self::CSIA, 'title' => 'CSIA', 'description' => 'Resources and exam guide'],
                        ]],
                        ['title' => 'Company', 'rows' => [
                            ['id' => self::FIND_LOCATION, 'title' => 'Find a location', 'description' => 'Phone, address, service area'],
                        ]],
                    ],
                ],
            ],
        ], $body);
    }

    private function searchLocations(WhatsAppNumber $number, string $to, string $query): void
    {
        // One or two letters match nearly every site, which reads as the bot
        // dumping its whole list rather than answering.
        if (mb_strlen($query) < 2) {
            $this->menu($number, $to);

            return;
        }

        $matches = $this->locationsMatching($query);

        if ($matches->isEmpty()) {
            $this->menu($number, $to, 'No location matched "'.mb_substr($query, 0, 40).'".');

            return;
        }

        $shown = $matches->take(self::MAX_LOCATIONS);

        $reply = $shown->map(fn (array $site) => $this->describe($site))->implode("\n\n");

        if ($matches->count() > self::MAX_LOCATIONS) {
            $reply .= "\n\n_Showing ".self::MAX_LOCATIONS.' of '.$matches->count()
                .'. Text a city for fewer._';
        }

        $this->text($number, $to, $reply);
    }

    /**
     * Sites whose city, state or name contains the query, with the facts a
     * tech needs. Read under the tenant scope, so a query can only ever see
     * this organization's sites.
     *
     * @return Collection<int, array<string, string>>
     */
    private function locationsMatching(string $query): Collection
    {
        $q = trim($query);

        /*
         * SHORT QUERIES MATCH WHOLE, NEVER AS A SUBSTRING.
         *
         * Measured against production before go-live, 2026-09-24. A tech
         * typing a branch code - "CHI" - substring-matched the company NAME,
         * and "chi" is inside "Chimney", so it matched all 43 A1 Chimney
         * sites and the real Chicago branch drowned among them. A state has
         * the same trap in addresses: "oh" is inside "John".
         *
         * So three characters or fewer is a code or a state, matched exactly:
         * the `state` field, or the branch code after " - " in the name.
         */
        if (mb_strlen($q) <= 3) {
            $ids = WebValue::query()
                ->where('path', '')
                ->where(fn ($w) => $w
                    ->where(fn ($x) => $x->where('key', 'state')->where('value', $q))
                    ->orWhere(fn ($x) => $x->where('key', 'company_name')->where(fn ($y) => $y
                        ->where('value', 'like', '% - '.$q)
                        ->orWhere('value', 'like', '% - '.$q.' - %'))))
                ->distinct()
                ->pluck('site_id');
        } else {
            /*
             * A CITY LIVES IN `service_area`, NOT IN `city`.
             *
             * Also measured, not assumed: Cleveland's branch has `city` =
             * "Warrensville Heights" and `service_area` = "Cleveland, Ohio".
             * The word a tech types is the one the site SERVES, so searching
             * `city` alone missed it. The address is searched too, for a
             * street or a suburb somebody remembers.
             */
            $like = '%'.addcslashes($q, '%_\\').'%';

            $ids = WebValue::query()
                ->where('path', '')
                ->whereIn('key', ['service_area', 'city', 'address'])
                ->where('value', 'like', $like)
                ->distinct()
                ->pluck('site_id');
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        /*
         * A1 CHIMNEY ONLY. The organization also runs Everdoors and A1 Garage
         * Door, which surfaced in the same search; this is the chimney staff
         * line, and a tech handed a garage-door number calls the wrong
         * business. `group` is the field that says which brand a site is.
         */
        $chimney = WebValue::query()
            ->where('path', '')
            ->where('key', 'group')
            ->where('value', self::BRAND)
            ->pluck('site_id');

        return WebValue::query()
            ->where('path', '')
            ->whereIn('site_id', $ids->intersect($chimney))
            ->whereIn('key', ['company_name', 'phone', 'address', 'service_area', 'maps_url'])
            ->get(['site_id', 'key', 'value'])
            ->groupBy('site_id')
            ->map(fn (Collection $rows) => $rows->pluck('value', 'key')->all())
            // An ad landing page duplicates its branch - same phone, same
            // area - so a tech would see every such branch twice.
            ->reject(fn (array $site) => (bool) preg_match('/-\s*ads\s*$/i', $site['company_name'] ?? ''))
            // A site with no phone and no address has nothing to tell a tech.
            ->filter(fn (array $site) => ($site['phone'] ?? '') !== '' || ($site['address'] ?? '') !== '')
            ->sortBy(fn (array $site) => $site['company_name'] ?? '')
            ->values();
    }

    /** @param  array<string, string>  $site */
    private function describe(array $site): string
    {
        $lines = ['*'.($site['company_name'] ?? 'A1 Chimney').'*'];

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
     * @param  bool  $preview  draw a card for a link in the body. On for a
     *                         location (its map link is worth a card), off for a
     *                         code answer, which carries no link at all.
     */
    private function text(WhatsAppNumber $number, string $to, string $body, bool $preview = true): void
    {
        $this->cloud->replyFreeForm($number, $to, [
            'type' => 'text',
            'text' => ['preview_url' => $preview, 'body' => $body],
        ], $body);
    }
}
