<?php

namespace Tests\Feature\Inspection;

use App\Support\Inspection\FieldSet;
use App\Support\Inspection\FieldType;
use App\Support\Inspection\RequiredFields;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The field types the A1 port needs beyond the original seven.
 *
 * ── Chosen from what the form ACTUALLY uses ──────────────────────
 *
 * Counted in `config.tsx` rather than taken from its type union, and the two
 * disagree in a way worth recording: the union declares `address`, and the real
 * inspection uses it ZERO times — its customer step asks for line 1, line 2,
 * city, state, zip and country as six separate fields. So there is no address
 * type here. A type is forever; one nobody uses is a permanent obligation
 * bought with nothing.
 *
 * `checkbox-group` is likewise absent: our `choice` with `multiple` is the same
 * control with a name we already have.
 */
class FieldTypesTest extends TestCase
{
    public function test_the_new_types_are_offered(): void
    {
        foreach (['textarea', 'email', 'phone', 'checkbox', 'divider', 'alert'] as $type) {
            $this->assertArrayHasKey($type, FieldType::ALL, "{$type} should be a field type");
        }

        // Not built, and the reason is in the class docblock: the real form
        // never asks for one.
        $this->assertArrayNotHasKey('address', FieldType::ALL);
    }

    public function test_a_textarea_email_phone_and_checkbox_are_ordinary_fields(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [
                ['key' => 'notes', 'label' => 'Notes', 'type' => 'textarea'],
                ['key' => 'email', 'label' => 'Email', 'type' => 'email'],
                ['key' => 'phone', 'label' => 'Phone', 'type' => 'phone'],
                ['key' => 'on_site', 'label' => 'Customer on site', 'type' => 'checkbox'],
            ],
        ]]);

        $this->assertCount(4, $set->toArray()[0]['fields']);
    }

    // ── the two that carry no answer ─────────────────────────────

    /**
     * A divider and an alert are furniture, and need no key.
     *
     * Every other field is keyed because its answer has to be stored under
     * something. These two store nothing, and demanding a key for them would be
     * asking an author to name a horizontal rule.
     */
    public function test_a_divider_and_an_alert_need_no_key(): void
    {
        $set = FieldSet::fromInput([[
            'fields' => [
                ['type' => 'divider', 'label' => 'Venting'],
                ['type' => 'alert', 'title' => 'M1803.3.4 CLEARANCE',
                 'body' => 'Type B vents shall be installed with a minimum clearance of 1 inch.'],
            ],
        ]]);

        $fields = $set->toArray()[0]['fields'];

        $this->assertSame('divider', $fields[0]['type']);
        $this->assertSame('alert', $fields[1]['type']);
        $this->assertSame('M1803.3.4 CLEARANCE', $fields[1]['title']);
    }

    public function test_an_alert_with_nothing_to_say_is_refused(): void
    {
        try {
            FieldSet::fromInput([[
                'fields' => [['type' => 'alert', 'title' => '', 'body' => '']],
            ]]);
            $this->fail('an alert with no words is a blank box on a form');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'something to say',
                implode(' ', array_merge(...array_values($e->errors()))),
            );
        }
    }

    /**
     * Furniture is never a missing answer.
     *
     * A `required` flag on a divider is meaningless, and honouring it would
     * block Submit on a horizontal rule with no way for anybody to satisfy it.
     */
    public function test_a_divider_or_alert_never_blocks_submit(): void
    {
        $screens = [[
            'fields' => [
                ['type' => 'divider', 'label' => 'Venting', 'required' => true],
                ['type' => 'alert', 'title' => 'Careful', 'body' => 'Mind the flue.',
                 'required' => true],
            ],
        ]];

        $this->assertNull(RequiredFields::firstMissing($screens, []));
    }

    /**
     * An unticked checkbox IS an answer, and a required one is not satisfied by it.
     *
     * `false` is a real answer to "is the customer on site". But a REQUIRED
     * checkbox means "you must tick this" — an acceptance — so false does not
     * satisfy it. Getting these the wrong way round either blocks a form nobody
     * can finish or waves through an unaccepted agreement.
     */
    public function test_a_required_checkbox_is_only_satisfied_by_ticking_it(): void
    {
        $screens = [[
            'fields' => [
                ['key' => 'accepted', 'label' => 'Customer accepts',
                 'type' => 'checkbox', 'required' => true],
            ],
        ]];

        $this->assertSame('Customer accepts', RequiredFields::firstMissing($screens, []));
        $this->assertSame('Customer accepts', RequiredFields::firstMissing($screens, ['accepted' => false]));
        $this->assertNull(RequiredFields::firstMissing($screens, ['accepted' => true]));
    }

    /** An optional checkbox left unticked is finished, not missing. */
    public function test_an_optional_checkbox_left_unticked_is_fine(): void
    {
        $screens = [[
            'fields' => [
                ['key' => 'on_site', 'label' => 'Customer on site', 'type' => 'checkbox'],
            ],
        ]];

        $this->assertNull(RequiredFields::firstMissing($screens, ['on_site' => false]));
    }
}
