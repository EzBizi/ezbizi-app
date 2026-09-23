<?php

namespace Tests\Feature\Workiz;

use App\Support\Workiz\WorkizJobAttributes;
use Tests\TestCase;

/**
 * One payload becomes one row's attributes.
 *
 * Every test here pins a defect an importer written from the published spec
 * would have shipped. The fixture's shape is captured from 400 real payloads;
 * see its header.
 */
class WorkizJobAttributesTest extends TestCase
{
    private function payload(array $overrides = []): array
    {
        return array_merge(require base_path('tests/Fixtures/workiz/job_payload.php'), $overrides);
    }

    public function test_it_flattens_the_fields_something_queries(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('DG0KYN', $out['remote_id']);
        $this->assertSame(900018531, $out['serial_id']);
        $this->assertSame(1002, $out['client_id']);
        $this->assertSame('07030', $out['postal_code']);
        $this->assertSame('Submitted', $out['status']);
        $this->assertSame('Free Estimate', $out['job_type']);
        $this->assertSame('Trey Brannan', $out['created_by']);
    }

    /**
     * THE TRAILING SPACE, and it is not cosmetic.
     *
     * Scheduling's zip map groups on this column. "Milwaukee " and "Milwaukee"
     * would be two service areas, and one territory's job history would be
     * split across two capacity allowances — the exact failure per-area
     * capacity exists to prevent.
     */
    public function test_service_area_is_trimmed_but_the_raw_value_survives(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('Upstate New York', $out['service_area']);
        $this->assertSame('Upstate New York ', $out['payload']['ServiceArea']);
    }

    /**
     * WORKIZ NEVER SENDS NULL. Measured over 400 payloads: absent is `''`.
     *
     * A column full of empty strings is not the same as a column full of NULLs
     * to anything that later asks "is this known", and `?? null` never fires
     * because the key is present. This is the defect the vendor's doc would
     * have produced on every nullable column in the table.
     */
    public function test_an_empty_string_lands_as_null(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertNull($out['sub_status']);
        $this->assertNull($out['unit']);
        $this->assertNull(
            WorkizJobAttributes::fromPayload($this->payload(['City' => '']))['city'],
            'City is empty on 14 of 400 real rows',
        );
    }

    /**
     * The same key arrives as two types on different rows. A cast that assumes
     * one of them is a fatal on the other, in a sweep that runs every minute.
     */
    public function test_a_key_that_changes_type_between_rows_does_not_fatal(): void
    {
        $asInt = WorkizJobAttributes::fromPayload($this->payload(['Unit' => 12, 'Tags' => '']));
        $asString = WorkizJobAttributes::fromPayload($this->payload(['Unit' => '#12', 'Tags' => ['RESCHEDULE']]));

        $this->assertSame('12', $asInt['unit']);
        $this->assertSame('#12', $asString['unit']);
        $this->assertSame(['RESCHEDULE'], $asString['payload']['Tags']);
    }

    /**
     * An array where a string belongs must not be stringified into "Array".
     */
    public function test_an_array_in_a_flat_column_lands_as_null_rather_than_the_word_array(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload(['City' => ['Hoboken']]));

        $this->assertNull($out['city']);
    }

    /**
     * Dates are 'Y-m-d H:i:s' and carry NO zone — `Timezone` is absent from
     * every real payload whatever the published spec says. So the string is
     * kept as wall time and the zone is left to whoever knows the franchise's.
     * Guessing UTC here moves every A-1 appointment by four or five hours.
     */
    public function test_dates_are_read_as_wall_time_in_the_workiz_format(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('2026-09-23 08:43:06', $out['booked_at']);
        $this->assertSame('2026-09-25 08:00:00', $out['scheduled_for']);
        $this->assertSame('2026-09-25 11:00:00', $out['scheduled_end']);
    }

    /**
     * A1 Tools' schema stores absent dates as '0000-00-00', which MySQL will
     * not accept into a DATETIME. An empty string is the same case.
     */
    public function test_a_zero_or_empty_date_lands_as_null(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload([
            'JobEndDateTime' => '0000-00-00 00:00:00',
            'LastStatusUpdate' => '',
        ]));

        $this->assertNull($out['scheduled_end']);
        $this->assertNull($out['last_status_update']);
    }

    /**
     * The account's own custom fields are undocumented and vary per account.
     * They are the reason `payload` exists; a fixed schema would drop them and
     * nothing would report it.
     */
    public function test_undocumented_custom_fields_survive_whole(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertSame('Gas System', $out['payload']['system_type']);
        $this->assertSame('Appointment Time: 8-11', $out['payload']['time_frames']);
    }

    /**
     * Placement comes from the CONNECTION, never from the payload — Workiz
     * cannot tell us which EzBizi franchise a job belongs to, and a payload
     * that could would be a caller-supplied tenant id.
     */
    public function test_it_does_not_decide_what_the_caller_owns(): void
    {
        $out = WorkizJobAttributes::fromPayload($this->payload());

        $this->assertArrayNotHasKey('organization_id', $out);
        $this->assertArrayNotHasKey('franchise_id', $out);
        $this->assertArrayNotHasKey('connection_id', $out);
        $this->assertArrayNotHasKey('synced_at', $out);
    }

    /**
     * A row with no id cannot be upserted idempotently, so the sweep has to be
     * able to recognise one rather than mint a row keyed on ''.
     */
    public function test_a_payload_with_no_id_is_recognisable(): void
    {
        $out = WorkizJobAttributes::fromPayload(['Status' => 'Submitted']);

        $this->assertSame('', $out['remote_id']);
    }
}
