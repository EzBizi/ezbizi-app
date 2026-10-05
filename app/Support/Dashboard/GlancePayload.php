<?php

namespace App\Support\Dashboard;

/**
 * Wire shape for one dashboard box (spec §11).
 *
 * The client draws slices / series or a sentence; it does not aggregate. Chart
 * kinds and coming-soon travel with the payload so a switcher can hide itself
 * without a second round trip.
 */
final class GlancePayload
{
    /**
     * ── What `$data['empty']` means, since it is the one people get wrong ──
     *
     * It asks whether this box is a STATEMENT or a READING. It is not a
     * sentence count and it is not a hint.
     *
     *   true   the client draws NO chart, whatever the
     *          payload carries, and sets every sentence in the muted note
     *          style. With no sentence at all it writes "Nothing to show."
     *   false  a chart when there is something to plot, every sentence as
     *          ordinary text.
     *
     * **Both render every sentence.** They did not until 2026-09-11: the
     * `true` branch drew the FIRST one and silently discarded the rest. That
     * cost three weeks of a customer seeing one of two dead inboxes named on
     * their home screen, and it is why the rule is written here rather than
     * in a note somebody has to have read. If you are setting `empty` to
     * dodge a renderer behaviour, the renderer is the thing to fix.
     *
     * The consequence to watch is the opposite one now: `true` SUPPRESSES a
     * chart and any figures. A box with slices to plot must not set it.
     *
     * **And the trap that replaced the old one, which is worse because the
     * table above invites it.** "No slices, nothing to plot, mark it empty"
     * is the obvious reading, and it is wrong exactly when the sentences are
     * the loudest thing the box has to say. Email reached this honestly the
     * morning after their glance was fixed for the opposite failure: every
     * mailbox disconnected means no slices, so `true` looked tidier — and it
     * would have rendered *"no mail is arriving from this address"* as a grey
     * aside. The old bug made a dead inbox appear nowhere; this one makes it
     * appear in muted text.
     *
     * So `empty` is not "did my query come back with nothing". It is **"is
     * there nothing here worth reading"**, and a failure a person must act on
     * is the opposite of that however few rows produced it.
     *
     * ── A decision already taken, so the next person does not retake it ──
     *
     * This one boolean gates THREE unrelated things (four until the money
     * lines left, 2026-09-29): the chart, the sentence styling and the
     * fallback. Two traps in two days, in opposite directions, is not bad
     * luck with a flag — it is what happens when several decisions read one key. Every one of the four is a legitimate
     * reason to set it, and setting it for one hands you the others
     * whether you considered them or not. Email wanted "no chart" and would
     * have accepted "muted" without noticing they had chosen it.
     *
     * **It is not being split today**, and that is deliberate: splitting it is
     * a wire change across eighteen glances to fix something that has so far
     * always been caught in review.
     *
     * **What should reopen it.** Not "a third trap appears" — Email's point,
     * and they are right. The first two were found by somebody reading this
     * table and by somebody reading a production symptom; a third could just
     * as easily be a glance rendering wrong for a month with nobody comparing
     * it to intent, and a trigger that waits for a trap to ANNOUNCE itself
     * will not fire. The signal is **somebody having to ask what `empty`
     * does**, which has now happened twice. On the third, split it.
     *
     * ── What the client actually draws out of `$data` ──────────────────
     *
     * Everything else you put here is carried over the wire and rendered by
     * nothing. `$data` is free-form and nothing validates it, so a key the
     * client has never heard of costs a query and a payload and produces no
     * pixels — silently, which is the mirror of the renderer dropping what it
     * was handed.
     *
     *   empty            the statement/reading switch above
     *   sentence         one line, or
     *   sentences        a list of them; both render in full
     *   slices           pie and bar, drawn when one total is above zero
     *   series           line and timeline, each with `points`
     *   figures          [{key, label, value, previous}] — the first is the
     *                    box's headline; a Small box shows only it. `label`
     *                    follows the number ("181 today"). `previous` is the
     *                    same count over DashboardPeriod::previous(), or null
     *                    where there is no history (a snapshot). Build each
     *                    with self::figure().
     *   attention        [{tone: caution|bad, text}], at most 3: what needs
     *                    somebody. Build with self::attention().
     *
     * MONEY ONLY BEHIND `<module>.financials`, and only as a figure built
     * with self::money() — never in a sentence, a note or an attention row.
     * Jose, 2026-09-29: "do not show any financial info on the dashboard
     * widgets" (a dispatcher who can open Jobs sees the Jobs box); refined
     * 2026-10-05: "as for the money remember its a role y permission things
     * where ( can see financials ) should be toggled" (UI queue 50). So a
     * reader without the permission gets no amount in the RESPONSE, not just
     * on screen — the response is readable whatever the client draws.
     * `DashboardTest::test_no_glance_sends_money_without_financials` checks
     * the raw body for every glance; its pair checks the figure arrives with
     * the permission.
     *
     * ── DECIDED 2026-09-15: do not send what nothing draws ──────────
     *
     * Jose was asked and was away; his standing word is to decide it as I
     * would recommend it, so: **a glance sends what its box draws, and
     * nothing else.** A figure with no reader is a query on every load and a
     * disclosure on every response, bought for nothing.
     *
     * `organization_total` is the live instance and the rule is general. It
     * is organization-wide, so a person whose reach is one franchise
     * receives the whole organization's count — invisible on screen,
     * readable in the response. Customers KEEPS it and is the shape of a
     * legitimate exception: its own docblock argues the case (§5.2 shares
     * the book, so the figure is not withheld from that reader anyway). The
     * other four carry no argument, which means nobody decided it — it was
     * copied.
     *
     * So the exception is available and it costs a sentence: if your box
     * needs a figure it does not draw, say why where you send it. An
     * undocumented one is a copy.
     *
     * Those four files belong to their own modules and are not mine to edit;
     * the decision is recorded here because this is what a glance author
     * reads, and routed to the deploy desk the same day.
     *
     * Measured 2026-09-11: `organization_total` is sent by FIVE glances —
     * BusinessProfile, Customers, Leads, Reviews, Web Management — and read
     * by nothing in `client/lib`. A pattern copied four times, not a slip.
     * Each is an extra count on every dashboard load for a figure nobody
     * sees. *Said "their modules' to keep or drop" when written — that was
     * true while nobody had decided, and the decision above settles it: drop
     * it unless the file argues for it. Still theirs to EDIT; not theirs to
     * decide case by case, or it stays five different answers.*
     *
     * And a key nothing draws is still SHIPPED. An organization-wide total
     * reaches a viewer whose reach is one franchise — invisible on screen,
     * readable in the response. Customers argues that one deliberately in its
     * own docblock (§5.2 shares the book); the others carry no such argument.
     * Scoping what a box DRAWS constrains nothing about what it SENDS.
     *
     * @param  string[]  $allowedPresentations
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $presentation,
        public readonly array $allowedPresentations,
        public readonly bool $snapshot,
        public readonly bool $comingSoon,
        public readonly array $options,
        public readonly array $data,
    ) {}

    /** @return array<string, mixed> */
    /**
     * One figure for the sized dashboard (Jose, 2026-10-05). A count, never
     * money: the no-money guard reads every figure.
     *
     * @return array{key: string, label: string, value: int, previous: int|null}
     */
    public static function figure(string $key, string $label, int $value, ?int $previous = null): array
    {
        return ['key' => $key, 'label' => $label, 'value' => $value, 'previous' => $previous];
    }

    /**
     * A money figure: `value` and `previous` in minor units of `currency`.
     * Send it ONLY when the reader holds the module's `financials`
     * permission, and one per currency — never a sum across currencies.
     *
     * @return array{key: string, label: string, value: int, previous: int|null, currency: string}
     */
    public static function money(string $key, string $label, int $minor, ?int $previousMinor, string $currency): array
    {
        return self::figure($key, $label, $minor, $previousMinor) + ['currency' => $currency];
    }

    /**
     * One row of what needs somebody. `bad` is broken or overdue; `caution`
     * is about to be.
     *
     * @param  'caution'|'bad'  $tone
     * @return array{tone: string, text: string}
     */
    public static function attention(string $tone, string $text): array
    {
        return ['tone' => $tone, 'text' => $text];
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'presentation' => $this->presentation,
            'allowed_presentations' => array_values($this->allowedPresentations),
            'snapshot' => $this->snapshot,
            'coming_soon' => $this->comingSoon,
            'options' => $this->options,
            'data' => $this->withNotes($this->data),
        ];
    }

    /**
     * `notes`: the sentences that are NOT a figure, for the sized client,
     * which draws figures + notes and never sentences (UI/UX, 2026-10-05).
     * `sentences` stays for installed builds that predate figures.
     *
     * Derived so twenty-two glances do not each keep two lists in step: a
     * sentence is a figure when it holds that figure's value as a number AND
     * every word of its label, plurals ignored ("1 vehicle needs service
     * soon." beside {1, "need service soon"}). Erring is one-sided on
     * purpose: a line that fails to match is drawn twice, visibly; nothing
     * is dropped unless both the number and all the words agree. A glance
     * that sets `notes` itself is left alone.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withNotes(array $data): array
    {
        $figures = $data['figures'] ?? [];
        if ($figures === [] || array_key_exists('notes', $data)) {
            return $data;
        }

        $sentences = $data['sentences'] ?? (isset($data['sentence']) ? [$data['sentence']] : []);

        $words = fn (string $text) => array_map(
            fn ($w) => rtrim($w, 's'),
            preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY),
        );

        $data['notes'] = array_values(array_filter($sentences, function ($line) use ($figures, $words) {
            $said = $words((string) $line);
            foreach ($figures as $figure) {
                if (in_array((string) $figure['value'], $said, true)
                    && array_diff($words($figure['label']), $said) === []) {
                    return false;
                }
            }

            return true;
        }));

        return $data;
    }
}
