<?php

namespace App\Support\Assistant;

use App\Models\AssistantEntry;
use App\Models\Organization;
use App\Models\TrainingGuide;
use App\Models\User;
use App\Models\WebValue;
use App\Support\Ai\GrokCli;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Training\GuideScreens;
use Illuminate\Support\Collection;

/**
 * What the assistant knows, and who may read which part of it.
 *
 * One brain, two mouths: WhatsAppStaffBot draws it as WhatsApp lists and text,
 * AssistantController as JSON for the app - Jose, 2026-09-24: "so we can also
 * have the same inside the app for people who dont have whatsapp". Neither may
 * decide visibility for itself, or the two would drift and one of them would
 * show a customer what the company pays each lender.
 *
 * ── Who is reading ───────────────────────────────────────────────
 *
 * `$viewer` is a User who works here, or null for anybody else. A viewer
 * reads the organization's answers plus those of every company and franchise
 * they reach - the same place engine Training and Inventory use - in both
 * audiences. Nobody-in-particular reads only organization-level PUBLIC answers:
 * a place's answer belongs to the people of that place, and a staff answer to
 * staff.
 *
 * Reach is the user's whole reach, never their saved view: a tech on WhatsApp
 * has no view switcher, and the app should answer them the same.
 */
final class AssistantBrain
{
    /** Words that carry no meaning in a question. */
    private const STOPWORDS = ['the', 'and', 'for', 'how', 'what', 'when', 'where', 'which', 'who', 'why',
        'does', 'need', 'needed', 'with', 'you', 'your', 'our', 'are', 'can', 'this', 'that', 'there', 'about',
        'have', 'has', 'from', 'into', 'many', 'much', 'should', 'would', 'could', 'tell', 'show', 'please'];

    public function __construct(private readonly SettingsResolver $settings) {}

    /** @return Collection<int, AssistantEntry> in menu order */
    public function entries(?User $viewer): Collection
    {
        $query = AssistantEntry::query();

        if ($viewer === null) {
            $query->whereNull('company_id')->where('audience', AssistantEntry::AUDIENCE_PUBLIC);
        } else {
            InventoryVisibility::constrain($query, $viewer, ViewScope::reachOf($viewer));
        }

        return $query->orderBy('position')->orderBy('title')->get();
    }

    /**
     * The menu: each section once, in the order of its first answer.
     *
     * @return Collection<string, Collection<int, AssistantEntry>> section => its answers
     */
    public function sections(?User $viewer): Collection
    {
        return $this->entries($viewer)->groupBy('section');
    }

    /**
     * One answer, only if this viewer may read it. Null for an id that does not
     * exist and for one that is not theirs alike, so a tapped id cannot be used
     * to learn that a hidden answer is there.
     */
    public function entry(?User $viewer, string $id): ?AssistantEntry
    {
        return $this->entries($viewer)->firstWhere('id', $id);
    }

    /**
     * The answers a typed question is about, best first. Empty when none is.
     *
     * No AI - Jose, 2026-09-25: "build free for now". Words are matched as the
     * start of a word ("inspect" finds "inspection"), a hit in the title counts
     * most, and only answers this viewer may read are searched, so a stranger
     * typing "finance" is not handed what the company pays each lender.
     *
     * ponytail: keyword scoring, no synonyms or typos ("flu" misses "flue").
     * An AI reading the same Library is the upgrade, if this falls short.
     *
     * @return Collection<int, AssistantEntry>
     */
    public function search(?User $viewer, string $query): Collection
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($query), $m);
        $words = array_values(array_unique(array_filter(
            $m[0],
            fn (string $w) => (ctype_digit($w) || mb_strlen($w) >= 3) && ! in_array($w, self::STOPWORDS, true),
        )));
        $entries = $this->entries($viewer);

        if ($words === [] || $entries->isEmpty()) {
            return collect();
        }

        $hits = fn (string $word, ?string $text) => (int) preg_match_all('/\b'.preg_quote($word, '/').'/iu', (string) $text);

        // A word in most answers - "chimney" - says nothing about which one is
        // meant, and would otherwise match every answer a little.
        if ($entries->count() >= 4) {
            $words = array_filter($words, fn (string $w) => $entries->filter(
                fn (AssistantEntry $e) => $hits($w, "{$e->section} {$e->title} {$e->description} {$e->body}") > 0,
            )->count() <= $entries->count() / 2);
        }

        $scored = $entries
            ->map(fn (AssistantEntry $e) => [$e, array_sum(array_map(fn (string $w) => 5 * min(1, $hits($w, $e->title))
                + 3 * min(1, $hits($w, $e->section))
                + 3 * min(1, $hits($w, $e->description))
                + min(3, $hits($w, $e->body)), $words))])
            ->filter(fn (array $s) => $s[1] > 0)
            ->sortByDesc(fn (array $s) => $s[1])
            ->values();

        // One answer when it clearly wins - twice the next one's score - so
        // "flue size" is answered, and "level 2" offers the Level 2 answers.
        if ($scored->count() > 1 && $scored[0][1] >= 2 * $scored[1][1]) {
            $scored = $scored->take(1);
        }

        return $scored->map(fn (array $s) => $s[0]);
    }

    /**
     * Grok's answer in its own words, from only the Library entries this viewer
     * may read and the branches the words name (Jose, 2026-10-12: "Grok
     * answers in own words", in the app and on WhatsApp). Null when Grok is
     * off or the Library does not hold the answer - never a made-up one.
     *
     * @return array{reply: string, entry_ids: list<string>}|null
     *
     * @throws \RuntimeException when Grok fails, so a caller can say so
     */
    public function aiAnswer(?User $viewer, Organization $organization, string $query, int $timeout = 60, bool $forWhatsApp = false): ?array
    {
        // Traced as the asker's (GrokCli::about).
        return GrokCli::about($viewer, fn () => $this->aiAnswerAs($viewer, $organization, $query, $timeout, $forWhatsApp));
    }

    private function aiAnswerAs(?User $viewer, Organization $organization, string $query, int $timeout, bool $forWhatsApp): ?array
    {
        if (! GrokCli::configured()) {
            return null;
        }

        $entries = $this->entries($viewer);
        $library = $entries->map(fn (AssistantEntry $e) => "[{$e->id}] {$e->section} / {$e->title}\n"
            .trim(($e->description ? $e->description."\n" : '').$e->body))->implode("\n\n");
        $sites = $this->locations($organization, $query)->take(5)
            ->map(fn (array $s) => json_encode($s))->implode("\n");
        $guides = $this->guideExcerpts($viewer, $query);

        $json = GrokCli::ask(
            "You answer staff questions for {$this->name($organization)}, a home-services business. Answer "
            .'ONLY from the Library, training guides and branch details below; if they do not contain the answer, set '
            .'answered to false and reply with an empty string. Never invent prices, phone numbers, '
            ."addresses or policies. Reply in 1-5 short plain sentences, in the question's language. "
            ."List the ids of the Library entries you used.\n".($forWhatsApp ? GrokCli::WHATSAPP_STYLE."\n" : '')."\nLIBRARY:\n".($library ?: '(empty)')
            ."\n\nBRANCHES:\n".($sites ?: '(none matched)')
            ."\n\nTRAINING GUIDES (quote video links exactly as given):\n".($guides ?: '(none)')
            ."\n\nQUESTION: ".$query,
            [
                'type' => 'object',
                'properties' => [
                    'answered' => ['type' => 'boolean'],
                    'reply' => ['type' => 'string'],
                    'entry_ids' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'required' => ['answered', 'reply', 'entry_ids'],
                'additionalProperties' => false,
            ],
            // A person is waiting, and on WhatsApp the one queue worker is:
            // under its 90 s retry_after (Meta review, 2026-10-13).
            $timeout,
        );

        $reply = trim((string) ($json['reply'] ?? ''));
        if (($json['answered'] ?? false) !== true || $reply === '') {
            return null;
        }

        return [
            'reply' => mb_substr($reply, 0, AssistantEntry::MAX_BODY),
            // Only ids this viewer could read: a hallucinated id is dropped.
            'entry_ids' => array_values(array_intersect((array) $json['entry_ids'], $entries->pluck('id')->all())),
        ];
    }

    /**
     * The training-guide topics that best match the question, as plain text,
     * from the guide for the asker's role (Jose, 2026-10-13: "i want the AI
     * to be able to answer them as well, without using the menu"). A role
     * with "tech" in its name reads the Technician guide, "dispatch" or
     * "call center" the Dispatcher guide, anyone else staff both; a stranger
     * none.
     *
     * ponytail: role read from its name and guides from their title, until a
     * guide carries an audience; keyword-picked topics, top 6.
     */
    private function guideExcerpts(?User $viewer, string $query): string
    {
        if ($viewer === null) {
            return '';
        }

        $role = mb_strtolower((string) $viewer->role?->name);
        $want = match (true) {
            str_contains($role, 'tech') => ['technician'],
            str_contains($role, 'dispatch'), str_contains($role, 'call center') => ['dispatcher'],
            default => ['technician', 'dispatcher'],
        };

        preg_match_all('/[\p{L}\p{N}]{3,}/u', mb_strtolower($query), $m);
        $words = array_values(array_diff(array_unique($m[0]), self::STOPWORDS));
        if ($words === []) {
            return '';
        }

        $scored = [];
        foreach (TrainingGuide::query()->where('status', TrainingGuide::PUBLISHED)->get() as $guide) {
            $title = mb_strtolower((string) $guide->title);
            if (! collect($want)->contains(fn ($w) => str_contains($title, $w))) {
                continue;
            }
            foreach (GuideScreens::topics($guide) as $t) {
                $text = GuideScreens::plainText($t['topic']);
                $hay = mb_strtolower($t['title'].' '.$t['chapter'].' '.$t['section'].' '.$text);
                $score = array_sum(array_map(fn ($w) => (str_contains(mb_strtolower($t['title']), $w) ? 3 : 0)
                    + min(3, substr_count($hay, $w)), $words));
                if ($score > 0) {
                    $scored[] = [$score, "[{$guide->title} › {$t['chapter']} › {$t['title']}]\n".mb_substr($text, 0, 1500)];
                }
            }
        }

        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        return implode("\n\n", array_column(array_slice($scored, 0, 6), 1));
    }

    public function name(Organization $organization): string
    {
        return (string) $this->settings->get('ai_assistance.name', $organization);
    }

    public function welcome(Organization $organization): string
    {
        return (string) $this->settings->get('ai_assistance.welcome', $organization);
    }

    /**
     * Sites whose city, state or name matches, with the facts a tech needs.
     *
     * READ, never copied: from the sites Web Management holds, at the moment
     * somebody asks. A list typed in anywhere else would be right the day it
     * was written and wrong the day a branch changed its number. Under the
     * tenant scope, so only ever this organization's sites.
     *
     * @return Collection<int, array<string, string>>
     */
    public function locations(Organization $organization, string $query): Collection
    {
        $q = trim($query);

        /*
         * SHORT QUERIES MATCH WHOLE, NEVER AS A SUBSTRING.
         *
         * Measured against production, 2026-09-24. A tech typing a branch code
         * - "CHI" - substring-matched the company NAME, and "chi" is inside
         * "Chimney", so it matched all 43 A1 Chimney sites and the real Chicago
         * branch drowned among them. A state has the same trap in addresses:
         * "oh" is inside "John". So three characters or fewer is a code or a
         * state, matched exactly: `state`, or the branch code after " - ".
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
             * A CITY LIVES IN `service_area`, NOT IN `city`. Measured:
             * Cleveland's branch has `city` = "Warrensville Heights" and
             * `service_area` = "Cleveland, Ohio". The word a tech types is the
             * one the site SERVES. The address is searched too, for a street
             * or a suburb somebody remembers.
             */
            $like = '%'.addcslashes($q, '%_\\').'%';

            $ids = WebValue::query()
                ->where('path', '')
                ->whereIn('key', ['service_area', 'city', 'address'])
                ->where('value', 'like', $like)
                ->distinct()
                ->pluck('site_id');
        }

        /*
         * One brand only, when the organization names one. A-1 also runs
         * Everdoors and A1 Garage Door, which surfaced in the same search; a
         * chimney tech handed a garage-door number calls the wrong business.
         * `group` is the Web Management field that says which brand a site is.
         */
        $group = trim((string) $this->settings->get('ai_assistance.location_group', $organization));

        if ($group !== '') {
            $ids = $ids->intersect(WebValue::query()
                ->where('path', '')
                ->where('key', 'group')
                ->where('value', $group)
                ->pluck('site_id'));
        }

        if ($ids->isEmpty()) {
            return collect();
        }

        return WebValue::query()
            ->where('path', '')
            ->whereIn('site_id', $ids)
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
}
