<?php

namespace Tests\Feature\Api;

use App\Support\Contracts\FieldSet;
use App\Support\Contracts\MergeTags;
use App\Support\Contracts\MergeValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Body sections, declared markup, and document.date parts.
 *
 * These are EzBizi grammar, not A-1 imports: conditionals keep or drop a
 * clause from answers, markup is punctuation we turn into our HTML, and
 * day/month/year are the same paperwork date as document.date.
 */
class HrContractCapabilitiesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_section_drops_when_the_field_is_blank(): void
    {
        $html = MergeTags::render(
            "Rate {{hourly_rate}}.\n\n{{#raise_amount}}Raise {{raise_amount}} every {{raise_period}}.{{/raise_amount}}\n\nDone.",
            [
                'hourly_rate'  => new MergeValue('Hourly rate', '22'),
                'raise_amount' => new MergeValue('Raise', null),
                'raise_period' => new MergeValue('Interval', 'month'),
            ],
        );

        $this->assertStringContainsString('Rate 22.', $html);
        $this->assertStringNotContainsString('Raise', $html);
        $this->assertStringContainsString('Done.', $html);
    }

    public function test_a_choice_section_keeps_only_the_picked_option(): void
    {
        $fields = FieldSet::fromInput([[
            'key' => 'pay_basis', 'label' => 'Pay basis', 'type' => 'choice',
            'options' => ['hourly', 'salary'], 'filled_by' => 'employer',
        ]]);

        MergeTags::validate(
            '{{#pay_basis.hourly}}OT{{/pay_basis.hourly}}{{#pay_basis.salary}}SAL{{/pay_basis.salary}}',
            $fields,
        );

        $html = MergeTags::render(
            '{{#pay_basis.hourly}}overtime clause{{/pay_basis.hourly}}{{#pay_basis.salary}}salary clause{{/pay_basis.salary}}',
            ['pay_basis' => new MergeValue('Pay basis', 'hourly')],
        );

        $this->assertStringContainsString('overtime clause', $html);
        $this->assertStringNotContainsString('salary clause', $html);
    }

    public function test_an_unclosed_section_is_refused_at_publish(): void
    {
        $this->expectException(ValidationException::class);

        MergeTags::validate(
            '{{#raise_amount}}never closed',
            FieldSet::fromInput([[
                'key' => 'raise_amount', 'label' => 'Raise', 'type' => 'number',
                'filled_by' => 'employer',
            ]]),
        );
    }

    public function test_headings_bold_lists_and_tables_are_markup_we_wrote(): void
    {
        $html = MergeTags::render(
            "# Duties\n\nDo **this** well.\n\n- One\n- Two\n\n| Item | Qty |\n| --- | --- |\n| Bag | 1 |\n",
            [],
        );

        $this->assertStringContainsString('<h2>Duties</h2>', $html);
        $this->assertStringContainsString('<strong>this</strong>', $html);
        $this->assertStringContainsString('<li>One</li>', $html);
        $this->assertStringContainsString('<th>Item</th>', $html);
        $this->assertStringContainsString('<td>Bag</td>', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_author_html_stays_visible_text(): void
    {
        $html = MergeTags::render('<b>not bold</b>', []);

        $this->assertStringContainsString('&lt;b&gt;not bold&lt;/b&gt;', $html);
    }

    public function test_document_date_parts_are_the_paperwork_date(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $alpha['org']->forceFill(['address' => '10 Main Street'])->save();

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'Parts',
                'fields' => [[
                    'key' => 'note', 'label' => 'Note', 'type' => 'text',
                    'filled_by' => 'employer', 'required' => false,
                ]],
                'body' => 'On this {{document.day}} day of {{document.month}}, {{document.year}} at {{organization.address}}.',
            ])
            ->assertCreated()
            ->json('data.id');

        $preview = $this->signedInAs($alpha['admin'])
            ->postJson("/api/v1/hr/contracts/{$id}/preview", [
                'fields' => [[
                    'key' => 'note', 'label' => 'Note', 'type' => 'text',
                    'filled_by' => 'employer', 'required' => false,
                ]],
                'body' => 'On this {{document.day}} day of {{document.month}}, {{document.year}} at {{organization.address}}.',
            ])
            ->assertOk()
            ->json('data.content');

        $today = now();
        $this->assertStringContainsString('On this '.$today->format('j').' day of '.$today->format('F').', '.$today->format('Y'), $preview);
        $this->assertStringContainsString('at 10 Main Street', $preview);
    }

    public function test_the_organization_address_is_the_business_address_on_the_profile(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $this->signedInAs($alpha['admin'])
            ->patchJson('/api/v1/organization', ['address' => '12 High Street'])
            ->assertOk()
            ->assertJsonPath('data.address', '12 High Street');

        $this->assertSame(
            '12 High Street',
            $this->inTenant($alpha['org'], fn () => $alpha['org']->fresh()->address),
        );
    }

    public function test_a_checklist_answer_merges_as_a_table_we_drew(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $id = $this->signedInAs($alpha['admin'])
            ->postJson('/api/v1/hr/contracts', [
                'name'   => 'Kit',
                'fields' => [[
                    'key' => 'tools', 'label' => 'Tools issued', 'type' => 'checklist',
                    'options' => ['Bag', 'Drill'], 'filled_by' => 'employer',
                ], [
                    'key' => 'sign', 'label' => 'Sign', 'type' => 'signature',
                    'filled_by' => 'employee',
                ]],
                'body' => "Issued:\n\n{{tools}}",
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertNotNull($id);
    }
}
