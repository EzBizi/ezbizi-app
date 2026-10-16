<?php

namespace Tests\Feature\Inspection;

use App\Models\InspectionReport;
use App\Support\Inspection\ReportDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * How a report is laid out, rather than what it contains.
 *
 * ── The problem this solves ──────────────────────────────────────
 *
 * A1's inspection has ~120 fields and the ported report prints every one as a
 * label-and-value row. `system_type` alone drives eleven branches and appears
 * once as an answer and never needs to be read — while "Chimney crown
 * condition" with its photograph, its code and its £3,199 of recommended work
 * is one row among a hundred and nineteen others.
 *
 * The old tool solves it with a `pdf` key per field: ROOT (a finding, with
 * everything it gathered grouped beneath it), TEXT (a plain line) and IGNORE
 * (answered for branching, never printed). Same three here.
 */
class ReportLayoutTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * A field marked `ignore` is answered and never printed.
     *
     * `system_type` is the case: it decides what the whole rest of the form
     * asks, and a customer reading the report does not need to be told
     * "masonry" eleven times.
     */
    public function test_an_ignored_field_does_not_appear(): void
    {
        $html = $this->render([[
            'name' => 'System',
            'fields' => [
                ['key' => 'system_type', 'label' => 'System type', 'type' => 'text',
                 'print' => 'ignore'],
                ['key' => 'crown', 'label' => 'Crown', 'type' => 'text'],
            ],
        ]], ['system_type' => 'masonry', 'crown' => 'Cracked']);

        $this->assertStringNotContainsString('System type', $html);
        $this->assertStringNotContainsString('masonry', $html);
        $this->assertStringContainsString('Crown', $html);
    }

    /**
     * A ROOT field is a finding: a heading, with what it gathered beneath it.
     *
     * The photograph, the explanation and the recommended work that belong to
     * one finding are printed under that finding rather than as three unrelated
     * rows further down the page.
     */
    public function test_a_root_field_becomes_a_heading_with_its_own_block(): void
    {
        $html = $this->render([[
            'name' => 'Crown',
            'fields' => [
                ['key' => 'crown', 'label' => 'Chimney crown condition', 'type' => 'choice',
                 'print' => 'root',
                 'options' => [
                     ['value' => 'ok', 'label' => 'Meets standards', 'variant' => 'pass'],
                     ['value' => 'not-ok', 'label' => 'Does not meet standards',
                      'variant' => 'fail'],
                 ]],
            ],
        ]], ['crown' => 'not-ok']);

        $this->assertStringContainsString('class="finding', $html);
        $this->assertStringContainsString('Chimney crown condition', $html);

        // The chosen option's LABEL, not its value. "not-ok" is a key for the
        // engine; "Does not meet standards" is what the customer is being told.
        $this->assertStringContainsString('Does not meet standards', $html);
        $this->assertStringNotContainsString('>not-ok<', $html);
    }

    /**
     * A finding's variant colours it, and a failure is legible as one.
     *
     * A customer scanning a report has to be able to see what failed without
     * reading every line.
     */
    public function test_a_failing_finding_is_marked_as_failing(): void
    {
        $html = $this->render([[
            'fields' => [
                ['key' => 'crown', 'label' => 'Crown', 'type' => 'choice', 'print' => 'root',
                 'options' => [
                     ['value' => 'ok', 'label' => 'Meets standards', 'variant' => 'pass'],
                     ['value' => 'not-ok', 'label' => 'Does not', 'variant' => 'fail'],
                 ]],
            ],
        ]], ['crown' => 'not-ok']);

        $this->assertStringContainsString('finding fail', $html);

        $passing = $this->render([[
            'fields' => [
                ['key' => 'crown', 'label' => 'Crown', 'type' => 'choice', 'print' => 'root',
                 'options' => [
                     ['value' => 'ok', 'label' => 'Meets standards', 'variant' => 'pass'],
                     ['value' => 'not-ok', 'label' => 'Does not', 'variant' => 'fail'],
                 ]],
            ],
        ]], ['crown' => 'ok']);

        $this->assertStringContainsString('finding pass', $passing);
    }

    /**
     * A report counts what it found.
     *
     * The single most useful line on a chimney report is "3 of 11 items did not
     * meet standards", and it is derivable only because options carry a
     * variant.
     */
    public function test_the_report_summarises_how_many_findings_failed(): void
    {
        $screens = [[
            'fields' => [
                ['key' => 'a', 'label' => 'A', 'type' => 'choice', 'print' => 'root',
                 'options' => [['value' => 'ok', 'label' => 'OK', 'variant' => 'pass'],
                               ['value' => 'no', 'label' => 'No', 'variant' => 'fail']]],
                ['key' => 'b', 'label' => 'B', 'type' => 'choice', 'print' => 'root',
                 'options' => [['value' => 'ok', 'label' => 'OK', 'variant' => 'pass'],
                               ['value' => 'no', 'label' => 'No', 'variant' => 'fail']]],
                ['key' => 'c', 'label' => 'C', 'type' => 'choice', 'print' => 'root',
                 'options' => [['value' => 'ok', 'label' => 'OK', 'variant' => 'pass'],
                               ['value' => 'na', 'label' => 'N/A', 'variant' => 'not_applicable']]],
            ],
        ]];

        $html = $this->render($screens, ['a' => 'no', 'b' => 'ok', 'c' => 'na']);

        $this->assertStringContainsString('1 of 3', $html);
    }

    /** With no findings at all there is nothing to summarise, and no empty banner. */
    public function test_a_report_with_no_findings_has_no_summary(): void
    {
        $html = $this->render([[
            'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']],
        ]], ['note' => 'All well']);

        $this->assertStringNotContainsString('did not meet', $html);
    }

    /**
     * Default is `text`, so every existing report is unchanged.
     *
     * Nothing in the product carries a `print` key. If the default were
     * anything else, this change would silently restyle every report ever
     * submitted — which the module's whole design forbids.
     */
    public function test_a_field_with_no_print_setting_is_a_plain_row(): void
    {
        $html = $this->render([[
            'fields' => [['key' => 'note', 'label' => 'Note', 'type' => 'text']],
        ]], ['note' => 'All well']);

        $this->assertStringContainsString('Note', $html);
        $this->assertStringContainsString('All well', $html);
        $this->assertStringNotContainsString('class="finding', $html);
    }

    /**
     * A choice printed as a plain line says the option's WORDS, not its key.
     *
     * The A1 port's "Level" is the case: the value is "2", and the customer is
     * owed "Level 2 — includes the exterior and flue". Findings already did
     * this; plain lines printed the key. A value no option carries any more
     * (renamed since the report was filed) still prints as it was stored.
     */
    public function test_a_plain_choice_line_prints_the_option_words(): void
    {
        $html = $this->render([[
            'fields' => [
                ['key' => 'level', 'label' => 'Level', 'type' => 'choice', 'print' => 'text',
                    'options' => [
                        ['value' => '1', 'label' => 'Level 1 — visual only'],
                        ['value' => '2', 'label' => 'Level 2 — includes the exterior and flue'],
                    ]],
                ['key' => 'extras', 'label' => 'Extras', 'type' => 'choice', 'print' => 'text',
                    'options' => [['value' => 'cap', 'label' => 'Rain cap']]],
            ],
        ]], ['level' => '2', 'extras' => ['cap', 'gone']]);

        $this->assertStringContainsString('Level 2 — includes the exterior and flue', $html);
        $this->assertStringNotContainsString('<div class="v">2</div>', $html);
        $this->assertStringContainsString('Rain cap, gone', $html);
    }

    /**
     * A section the inspection never asked is not printed and not counted.
     *
     * A built-in fireplace report printed the masonry, wood-stove, furnace and
     * electric sections as blank findings and said "1 of 31 items" — found by
     * rendering the A1 form end to end (2026-10-16).
     */
    public function test_questions_the_inspection_did_not_ask_are_not_printed(): void
    {
        $html = $this->render([
            ['name' => 'The system', 'fields' => [
                ['key' => 'system_type', 'label' => 'System', 'type' => 'choice', 'print' => 'ignore', 'options' => ['masonry', 'wood']],
                ['type' => 'divider', 'label' => 'Wood stove', 'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood']],
                ['key' => 'stove', 'label' => 'Stove condition', 'type' => 'choice', 'print' => 'root',
                    'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'],
                    'options' => [['value' => 'ok', 'variant' => 'pass']]],
                ['key' => 'firebox', 'label' => 'Firebox condition', 'type' => 'choice', 'print' => 'root',
                    'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'masonry'],
                    'options' => [['value' => 'ok', 'variant' => 'pass'], ['value' => 'bad', 'variant' => 'fail']]],
            ]],
            ['name' => 'Exterior', 'visible_if' => ['field' => 'system_type', 'op' => 'eq', 'value' => 'wood'], 'fields' => [
                ['key' => 'crown', 'label' => 'Chimney crown', 'type' => 'text'],
            ]],
        ], ['system_type' => 'masonry', 'firebox' => 'bad']);

        $this->assertStringContainsString('Firebox condition', $html);
        $this->assertStringNotContainsString('Stove condition', $html);
        $this->assertStringNotContainsString('Wood stove', $html);
        $this->assertStringNotContainsString('Chimney crown', $html);
        $this->assertStringContainsString('1 of 1 item did not meet standards', $html);
    }

    /** @param list<array<string, mixed>> $screens */
    private function render(array $screens, array $answers): string
    {
        $tenant = $this->provisionTenant('Alpha');

        return $this->inTenant($tenant['org'], function () use ($tenant, $screens, $answers) {
            $customer = \App\Models\Customer::create([
                'owning_franchise_id' => $tenant['franchise']->id,
                'number' => 'C-'.bin2hex(random_bytes(3)),
                'name' => '14 Maple',
            ]);

            $definition = \App\Models\InspectionDefinition::create([
                'name' => 'Level 1',
                'created_by' => $tenant['admin']->id,
                'screens' => $screens,
            ]);

            $version = \App\Models\InspectionDefinitionVersion::create([
                'inspection_definition_id' => $definition->id,
                'version' => 1,
                'layout_preset' => 'step_strip',
                'screens' => $screens,
                'published_by' => $tenant['admin']->id,
            ]);

            $report = InspectionReport::create([
                'customer_id' => $customer->id,
                'inspection_definition_id' => $definition->id,
                'inspection_definition_version_id' => $version->id,
                'submitted_by' => $tenant['admin']->id,
                'submitted_at' => now(),
                'answers' => $answers,
                'layout_preset' => 'step_strip',
                'definition_name' => 'Level 1',
            ]);

            return app(ReportDocument::class)->html($report);
        });
    }
}
