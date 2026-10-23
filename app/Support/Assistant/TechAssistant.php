<?php

namespace App\Support\Assistant;

use App\Models\AiTrace;
use App\Models\AssistantAnswer;
use App\Models\AssistantEntry;
use App\Models\AssistantJob;
use App\Models\AssistantManual;
use App\Models\AssistantPart;
use App\Models\Organization;
use App\Models\User;
use App\Support\Ai\GrokCli;
use App\Support\Settings\SettingsResolver;
use Illuminate\Support\Collection;

/**
 * The Chimney Tech Assistant (approved mockup tech-assistant.html, 2026-10-23):
 * parts questions answered from the business's own parts list and manuals,
 * through Grok, with the tech's open job remembered between messages.
 *
 * ── No guessing, enforced here and not only asked for ────────────
 *
 * The prompt tells Grok never to name a part number it was not given. That is
 * a request; guard() is the rule: any part-number-shaped token in the reply
 * that is in neither the supplied part rows nor the supplied manual pages
 * forces DO NOT ORDER YET and a warning line. READY TO ORDER is also refused
 * unless model and fuel are known and a used part is confirmed or from a
 * manual/manufacturer.
 *
 * ponytail: keyword retrieval (no vector store), no web search, no voice.
 */
final class TechAssistant
{
    public const NOT_READY = "I'm not ready to approve ordering this part yet.";

    public const READY = 'READY TO ORDER';

    public const NOT_YET = 'DO NOT ORDER YET';

    private const MAX_PARTS = 15;

    private const MAX_PAGES = 6;

    private const STOPWORDS = ['the', 'and', 'for', 'need', 'with', 'what', 'which', 'this', 'that', 'have', 'from',
        'part', 'parts', 'number', 'please', 'unit', 'para', 'que', 'con', 'una', 'los', 'las', 'del'];

    public function __construct(private readonly SettingsResolver $settings) {}

    public function enabled(Organization $organization): bool
    {
        return (bool) $this->settings->get('ai_assistance.tech_mode', $organization);
    }

    /** Sounds like a parts / unit question (brand, part words, fuel, a rating plate). ponytail: a word list. */
    public static function looksTechnical(string $text): bool
    {
        return preg_match('/\b(pilot|thermocouple|thermopile|valve|igniter|ignitor|ignition|ipi|millivolt|rating plate|plate|serial|model|part ?#|part number|gas valve|burner|orifice|regulator|blower|fan kit|module|receiver|remote|flame sensor|spark|electrode|\bng\b|\blp\b|propane|natural gas|insert|fireplace|stove|log set|heat ?& ?glo|heatilator|majestic|napoleon|regency|mendota|lopi|valor|kozy|empire|superior|monessen|vermont castings|quadra|harman|sit 8\d\d|piloto|v[aá]lvula|chimenea|placa|termopar|נורה|ברז|אח|קמין)\b/iu', $text) === 1
            || self::isNewJob($text);
    }

    public static function isNewJob(string $text): bool
    {
        return preg_match('/(new job|nuevo trabajo|עבודה חדשה)/iu', $text) === 1;
    }

    /** The person's open job, a new one after "new job" or 12 idle hours. */
    public function job(User $user, string $channel, bool $fresh = false): AssistantJob
    {
        $open = AssistantJob::query()->where('user_id', $user->getKey())->whereNull('closed_at')->latest('updated_at')->first();

        if ($open !== null && ($fresh || $open->updated_at->lt(now()->subHours(AssistantJob::IDLE_HOURS)))) {
            $open->forceFill(['closed_at' => now()])->save();
            $open = null;
        }

        return $open ?? AssistantJob::create(['user_id' => $user->getKey(), 'channel' => $channel]);
    }

    /**
     * Answer one tech message. Null when Grok is off or had nothing to say.
     *
     * @param  array<string, string>  $files  name => bytes (a rating-plate photo)
     * @return array{reply: string, answer: ?AssistantAnswer}|null
     *
     * @throws \RuntimeException when Grok fails
     */
    public function answer(User $user, Organization $organization, string $question, string $channel, array $files = [], int $timeout = 60): ?array
    {
        $question = trim($question);
        $newJob = self::isNewJob($question);
        $job = $this->job($user, $channel, $newJob);

        // "new job" alone: nothing to ask Grok.
        if ($newJob && trim((string) preg_replace('/(new job|nuevo trabajo|עבודה חדשה)[\s.!:,-]*/iu', '', $question)) === '' && $files === []) {
            return ['reply' => "\u{1F195} New job started. Send the rating plate photo, or the brand, model and fuel (NG/LP).", 'answer' => null];
        }

        if (! GrokCli::configured()) {
            return null;
        }

        $words = $this->words($question.' '.$job->brand.' '.$job->model);
        $parts = $this->parts($words);
        $pages = $this->pages($words);
        $library = app(AssistantBrain::class)->search($user, $question)->take(5);

        $since = now();
        $json = GrokCli::about($user, fn () => GrokCli::ask(
            $this->prompt($organization, $job, $question, $parts, $pages, $library, $channel, $files !== []),
            self::schema(),
            $timeout,
            $files,
        ));

        $reply = trim((string) ($json['reply'] ?? ''));
        if ($reply === '') {
            return null;
        }

        // The job, from what Grok read (a plate) or was told.
        $updates = array_filter(array_intersect_key((array) ($json['job'] ?? []), array_flip(['brand', 'model', 'serial', 'fuel', 'ignition'])),
            fn ($v) => is_string($v) && trim($v) !== '');
        if (isset($updates['fuel'])) {
            $fuel = strtoupper(trim($updates['fuel']));
            $updates['fuel'] = in_array($fuel, ['NG', 'LP'], true) ? $fuel : null;
        }
        $job->fill(array_map(fn ($v) => $v === null ? null : mb_substr(trim($v), 0, 60), array_filter($updates)));

        $usedParts = $parts->whereIn('id', (array) ($json['part_ids'] ?? []))->values();
        $orderCheck = in_array($json['order_check'] ?? null, ['ready', 'not_yet'], true) ? $json['order_check'] : null;

        [$reply, $orderCheck] = self::guard($reply, $orderCheck, $parts, $pages, $job, $usedParts, $question);

        $job->parts_discussed = array_values(array_unique(array_merge(
            (array) $job->parts_discussed,
            $usedParts->pluck('part_number')->filter()->all(),
        )));
        $job->touch();
        $job->save();

        $answer = AssistantAnswer::create([
            'user_id' => $user->getKey(),
            'job_id' => $job->getKey(),
            'channel' => $channel,
            'question' => $question === '' ? '(photo)' : mb_substr($question, 0, 4000),
            'answer' => $reply,
            'order_check' => $orderCheck,
            'confidence' => is_string($json['confidence'] ?? null) ? mb_substr($json['confidence'], 0, 20) : null,
            'part_ids' => $usedParts->pluck('id')->all(),
            'manual_pages' => collect((array) ($json['manual_pages'] ?? []))
                ->filter(fn ($p) => is_array($p) && $pages->contains(fn ($q) => $q['manual_id'] === ($p['manual_id'] ?? null) && $q['page'] === (int) ($p['page'] ?? 0)))
                ->map(fn ($p) => ['manual_id' => $p['manual_id'], 'page' => (int) $p['page']])->values()->all(),
            'trace_id' => AiTrace::query()->where('organization_id', $organization->getKey())
                ->where('scope', 'TechAssistant')->where('created_at', '>=', $since->copy()->subSecond())
                ->latest('created_at')->value('id'),
        ]);

        return ['reply' => $reply, 'answer' => $answer];
    }

    /**
     * The hallucination guard. Any part number in the reply that is not in
     * the supplied rows or pages, or a READY TO ORDER without model, fuel and
     * a good source, becomes DO NOT ORDER YET.
     *
     * @param  Collection<int, AssistantPart>  $parts
     * @param  Collection<int, array{manual_id: string, page: int, title: string, text: string}>  $pages
     * @param  Collection<int, AssistantPart>  $usedParts
     * @return array{0: string, 1: ?string}
     */
    public static function guard(string $reply, ?string $orderCheck, Collection $parts, Collection $pages, AssistantJob $job, Collection $usedParts, string $question = ''): array
    {
        $norm = fn (string $s) => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $s));

        $known = $parts->flatMap(fn (AssistantPart $p) => [$p->part_number, $p->superseded_by])->filter()->map($norm)->all();
        $context = $norm($pages->pluck('text')->implode(' '));
        // Model names and serials carry digits too ("6000C"): not part numbers.
        $notParts = $parts->flatMap(fn (AssistantPart $p) => [$p->model, ...(array) $p->model_aliases, $p->serial_from, $p->serial_to])
            ->merge([$job->model, $job->serial])->filter()->map($norm)->all();
        $questionNorm = $norm($question.' '.$job->model.' '.$job->serial);

        $unknown = [];
        foreach (self::partNumbers($reply) as $token) {
            $n = $norm($token);
            if ($n === '' || in_array($n, $known, true) || in_array($n, $notParts, true)
                || ($context !== '' && str_contains($context, $n)) || str_contains($questionNorm, $n)) {
                continue;
            }
            $unknown[] = $token;
        }
        $unknown = array_values(array_unique($unknown));

        $goodSource = $usedParts->contains(fn (AssistantPart $p) => $p->status === 'confirmed'
            || (in_array($p->source_type, AssistantPart::ORDERABLE_SOURCES, true) && ! in_array($p->status, ['needs_review', 'unverified', 'discontinued'], true)));
        $readyAllowed = $unknown === [] && filled($job->model) && in_array($job->fuel, ['NG', 'LP'], true) && $goodSource;

        $saysReady = stripos($reply, self::READY) !== false;
        if (($orderCheck === 'ready' || $saysReady) && ! $readyAllowed) {
            $orderCheck = 'not_yet';
            $reply = str_ireplace(self::READY, self::NOT_YET, $reply);
            if (stripos($reply, self::NOT_YET) === false) {
                $reply .= "\nORDER CHECK: ".self::NOT_YET;
            }
        }

        if ($unknown !== []) {
            $orderCheck = 'not_yet';
            $reply .= "\n\u{26A0}\u{FE0F} WARNING: ".implode(', ', $unknown)
                .' is not in our parts list or manuals. Verify it before ordering.';
        }

        return [$reply, $orderCheck];
    }

    /**
     * Part-number-shaped tokens: letters/digits joined by - . / with a digit
     * in them ("2166-302", "0.885.003", "TP-750"), a run of 5+ digits, or
     * whatever follows "PART #:".
     *
     * @return list<string>
     */
    public static function partNumbers(string $text): array
    {
        $found = [];
        preg_match_all('/(?<![\w.\-])[A-Za-z0-9]+(?:[.\-\/][A-Za-z0-9]+)+(?![\w\-])/u', $text, $m);
        foreach ($m[0] as $t) {
            if (preg_match('/\d/', $t) && preg_match('/\d{2,}|[A-Za-z]\d|\d[A-Za-z]/', $t) && strlen($t) >= 4
                && ! preg_match('/^\d{1,2}[\/\-]\d{1,2}([\/\-]\d{2,4})?$/', $t)) {
                $found[] = rtrim($t, '.');
            }
        }
        preg_match_all('/(?<![\w.\-])\d{5,}(?![\w\-])/u', $text, $m);
        $found = array_merge($found, $m[0]);
        preg_match_all('/PART\s*#\s*:\s*([^\s\n,;]+)/iu', $text, $m);
        foreach ($m[1] as $t) {
            if (preg_match('/\d/', $t)) {
                $found[] = rtrim($t, '.');
            }
        }

        return array_values(array_unique($found));
    }

    /** @return list<string> */
    private function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\-.]*/u', mb_strtolower($text), $m);

        return array_values(array_unique(array_filter(
            array_map(fn ($w) => rtrim($w, '.-'), $m[0]),
            fn (string $w) => (preg_match('/\d/', $w) || mb_strlen($w) >= 3) && ! in_array($w, self::STOPWORDS, true),
        )));
    }

    /** @return Collection<int, AssistantPart> best first */
    private function parts(array $words): Collection
    {
        if ($words === []) {
            return collect();
        }

        return AssistantPart::query()->get()
            ->map(function (AssistantPart $p) use ($words) {
                $model = mb_strtolower($p->brand.' '.$p->model.' '.implode(' ', (array) $p->model_aliases));
                $name = mb_strtolower($p->part_name.' '.implode(' ', (array) $p->part_aliases));
                $number = mb_strtolower($p->part_number.' '.$p->superseded_by);
                $score = 0;
                foreach ($words as $w) {
                    $score += (str_contains($model, $w) ? 3 : 0) + (str_contains($name, $w) ? 2 : 0) + (str_contains($number, $w) ? 5 : 0);
                }

                return [$p, $score];
            })
            ->filter(fn ($s) => $s[1] > 0)
            ->sortByDesc(fn ($s) => $s[1])
            ->take(self::MAX_PARTS)
            ->map(fn ($s) => $s[0])
            ->values();
    }

    /** @return Collection<int, array{manual_id: string, page: int, title: string, text: string}> best first */
    private function pages(array $words): Collection
    {
        if ($words === []) {
            return collect();
        }

        $scored = [];
        foreach (AssistantManual::query()->get() as $manual) {
            $head = mb_strtolower($manual->brand.' '.$manual->models.' '.$manual->title);
            $boost = array_sum(array_map(fn ($w) => str_contains($head, $w) ? 3 : 0, $words));
            foreach ((array) $manual->pages as $i => $text) {
                $hay = mb_strtolower((string) $text);
                $score = array_sum(array_map(fn ($w) => min(3, substr_count($hay, $w)), $words));
                if ($score > 0) {
                    $scored[] = [$score + $boost, [
                        'manual_id' => (string) $manual->id,
                        'page' => $i + 1,
                        'title' => trim($manual->brand.' '.$manual->models.' - '.$manual->title.($manual->revision ? ' rev '.$manual->revision : '')),
                        'text' => mb_substr((string) $text, 0, 2500),
                    ]];
                }
            }
        }

        usort($scored, fn ($a, $b) => $b[0] <=> $a[0]);

        return collect(array_column(array_slice($scored, 0, self::MAX_PAGES), 1));
    }

    private function prompt(Organization $organization, AssistantJob $job, string $question, Collection $parts, Collection $pages, Collection $library, string $channel, bool $photo): string
    {
        $extra = trim((string) $this->settings->get('ai_assistance.tech_prompt', $organization));

        $partRows = $parts->map(fn (AssistantPart $p) => json_encode([
            'id' => $p->id, 'brand' => $p->brand, 'model' => $p->model, 'model_aliases' => $p->model_aliases,
            'part' => $p->part_name, 'aliases' => $p->part_aliases, 'part_number' => $p->part_number, 'fuel' => $p->fuel,
            'serial_from' => $p->serial_from, 'serial_to' => $p->serial_to, 'status' => $p->status,
            'superseded_by' => $p->superseded_by, 'source' => $p->source_type, 'source_ref' => $p->source_ref, 'notes' => $p->notes,
        ], JSON_UNESCAPED_UNICODE))->implode("\n");
        $pageText = $pages->map(fn ($p) => "[manual_id {$p['manual_id']} page {$p['page']}] {$p['title']}\n{$p['text']}")->implode("\n\n");
        $libText = $library->map(fn (AssistantEntry $e) => "{$e->title}: ".mb_substr($e->body, 0, 800))->implode("\n");
        $jobText = json_encode(['brand' => $job->brand, 'model' => $job->model, 'serial' => $job->serial,
            'fuel' => $job->fuel, 'ignition' => $job->ignition, 'parts_discussed' => $job->parts_discussed], JSON_UNESCAPED_UNICODE);

        return "You are the chimney and gas-fireplace tech assistant for {$organization->name}. Technicians in the field ask you about units and parts.\n"
            ."RULES:\n"
            ."1. NO GUESSING. Never state a part number that is not in PARTS ROWS or MANUAL PAGES below. If you are not sure, start the reply with exactly \"".self::NOT_READY."\" then a MISSING: list of what is needed (exact model / rating plate photo, serial, fuel NG or LP, ...).\n"
            ."2. CONFIDENCE is one of CONFIRMED (part row confirmed or manual page), HIGH CONFIDENCE, POSSIBLE, UNVERIFIED.\n"
            ."3. ORDER CHECK: \"".self::READY."\" only when the model AND fuel (NG/LP) are known AND the part comes from a confirmed row or a manual page; otherwise \"".self::NOT_YET."\". \"I think\" is not known.\n"
            ."4. FORMAT for a parts answer, one per line:\nMODEL: ...\nSERIAL: ...\nFUEL: ...\nIGNITION: ...\nPART: ...\nPART #: ...\nCONFIDENCE: ...\nSOURCE: (manual title, rev, page, or parts list)\nNOTE: ...\nORDER CHECK: ...\n"
            ."5. If a part is superseded or discontinued, say so and name the replacement from the rows.\n"
            ."6. SAFETY: never tell anyone to bypass, jumper or remove a safety device (thermocouple, thermopile, spill switch, limit, ODS). Smell of gas or CO alarm: stop, shut off gas if safe, evacuate, call the gas utility / 911.\n"
            ."7. Reply in the technician's language (English, Spanish or Hebrew). Understand shorthand (assy, t-couple, tpile, IPI, mV, NG, LP).\n"
            ."8. If asked to \"write for customer\" (or similar), write a short polite message for the homeowner instead of the format; never invent prices or dates not given; set order_check to null.\n"
            ."9. If a photo is attached, read it (rating plate, valve label): extract brand, model, serial, fuel, ignition into job.\n"
            ."10. No web access: if the rows and pages do not hold it, say it is UNVERIFIED and ask them to check.\n"
            ."Fill job with anything newly learned (empty string when unknown). part_ids: ids of PARTS ROWS you used. manual_pages: the manual_id and page you used.\n"
            .($channel === 'whatsapp' ? "Plain text for WhatsApp; no Markdown headings or tables.\n" : '')
            .($extra !== '' ? "\nADMIN INSTRUCTIONS:\n{$extra}\n" : '')
            ."\nCURRENT JOB: {$jobText}\n"
            ."\nPARTS ROWS:\n".($partRows ?: '(none matched)')
            ."\n\nMANUAL PAGES:\n".($pageText ?: '(none matched)')
            ."\n\nLIBRARY:\n".($libText ?: '(none)')
            .($photo ? "\n\nA PHOTO IS ATTACHED in the working folder: read it with read_file." : '')
            ."\n\nTECHNICIAN: ".($question === '' ? '(sent a photo)' : $question);
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $str = ['type' => 'string'];

        return [
            'type' => 'object',
            'properties' => [
                'reply' => $str,
                'job' => [
                    'type' => 'object',
                    'properties' => ['brand' => $str, 'model' => $str, 'serial' => $str, 'fuel' => $str, 'ignition' => $str],
                    'required' => ['brand', 'model', 'serial', 'fuel', 'ignition'],
                    'additionalProperties' => false,
                ],
                'order_check' => ['type' => ['string', 'null'], 'enum' => ['ready', 'not_yet', null]],
                'confidence' => ['type' => ['string', 'null']],
                'part_ids' => ['type' => 'array', 'items' => $str],
                'manual_pages' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['manual_id' => $str, 'page' => ['type' => 'integer']],
                    'required' => ['manual_id', 'page'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['reply', 'job', 'order_check', 'confidence', 'part_ids', 'manual_pages'],
            'additionalProperties' => false,
        ];
    }

    /** "en", "es" or "he", from the text. */
    public static function language(string $text): string
    {
        if (preg_match('/\p{Hebrew}/u', $text)) {
            return 'he';
        }

        return preg_match('/\b(hola|gracias|necesito|que|para|esto|placa|v[aá]lvula|piloto|est[aá]|por favor|trabajo)\b/iu', $text) ? 'es' : 'en';
    }
}
