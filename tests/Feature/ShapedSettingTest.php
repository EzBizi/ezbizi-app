<?php

namespace Tests\Feature;

use App\Support\Settings\SettingDefinition;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The shaped setting types (plan §6, §2.1 layer 5).
 *
 * These exist because `json` validated nothing. `{"foo": 1}` was an acceptable
 * set of business hours right up until the code that reads them tried to open
 * on Monday — and there is no worse place to discover a bad setting than in the
 * feature that depends on it.
 */
class ShapedSettingTest extends TestCase
{
    private function hours(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'test.hours', label: 'Hours',
            type: SettingDefinition::TYPE_HOURS, default: [],
        );
    }

    private function range(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'test.range', label: 'Range',
            type: SettingDefinition::TYPE_TIME_RANGE, default: [],
        );
    }

    private function list(): SettingDefinition
    {
        return new SettingDefinition(
            key: 'test.list', label: 'List',
            type: SettingDefinition::TYPE_LIST, default: [],
        );
    }

    /** @return array<string,mixed> */
    private function week(mixed $override = null, string $day = 'monday'): array
    {
        $week = array_fill_keys(
            SettingDefinition::WEEKDAYS,
            ['open' => '08:00', 'close' => '17:00'],
        );

        if (func_num_args() > 0) {
            $week[$day] = $override;
        }

        return $week;
    }

    // Audit 2026-11-27: "5" decoded to an int and the array return type threw a 500.
    public function test_scalar_json_is_refused_not_a_type_error(): void
    {
        foreach ([$this->hours(), $this->list()] as $definition) {
            try {
                $definition->cast('5');
                $this->fail('A scalar was accepted.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('object or list', $e->getMessage());
            }
        }
    }

    public function test_a_full_week_is_accepted_and_null_means_closed(): void
    {
        $cast = $this->hours()->cast($this->week(null, 'sunday'));

        $this->assertNull($cast['sunday']);
        $this->assertSame(['open' => '08:00', 'close' => '17:00'], $cast['monday']);
    }

    public function test_the_week_comes_back_in_week_order(): void
    {
        $shuffled = array_reverse($this->week(), preserve_keys: true);

        // Order is what the client draws the rows in. Round-tripping a value
        // should not silently reorder somebody's week.
        $this->assertSame(
            SettingDefinition::WEEKDAYS,
            array_keys($this->hours()->cast($shuffled)),
        );
    }

    public function test_a_missing_day_is_refused_rather_than_assumed_closed(): void
    {
        $week = $this->week();
        unset($week['saturday']);

        // The alternative is a client that drops a day by accident quietly
        // shutting the business on Saturdays.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing saturday');

        $this->hours()->cast($week);
    }

    public function test_a_time_that_is_not_a_time_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('monday open');

        $this->hours()->cast($this->week(['open' => '8am', 'close' => '17:00']));
    }

    public function test_an_out_of_range_hour_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->hours()->cast($this->week(['open' => '25:00', 'close' => '26:00']));
    }

    public function test_closing_after_midnight_is_allowed(): void
    {
        // A bar closing at 02:00 is not a typo, and refusing it would be this
        // validator deciding what trade the tenant is in.
        $cast = $this->hours()->cast($this->week(['open' => '18:00', 'close' => '02:00']));

        $this->assertSame(['open' => '18:00', 'close' => '02:00'], $cast['monday']);
    }

    public function test_a_range_is_both_or_neither(): void
    {
        $this->assertSame(
            ['start' => null, 'end' => null],
            $this->range()->cast(['start' => null, 'end' => null]),
        );

        $this->assertSame(
            ['start' => '22:00', 'end' => '07:00'],
            $this->range()->cast(['start' => '22:00', 'end' => '07:00']),
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('both a start and an end');

        $this->range()->cast(['start' => '22:00', 'end' => null]);
    }

    public function test_a_list_keeps_its_order_and_trims(): void
    {
        // Order is content — a status list is a pipeline, and the sequence is
        // what a board reads to lay its columns out.
        $this->assertSame(
            ['scheduled', 'in_progress', 'done'],
            $this->list()->cast(['scheduled', ' in_progress ', 'done']),
        );
    }

    public function test_a_named_list_keeps_value_and_label(): void
    {
        $this->assertSame(
            [
                ['value' => 'in_progress', 'label' => 'In progress'],
                ['value' => 'done', 'label' => 'Done'],
            ],
            $this->list()->cast([
                ['value' => 'in_progress', 'label' => 'In progress', 'was' => 'scheduled'],
                ['value' => 'done', 'label' => ' Done '],
            ]),
        );
    }

    public function test_a_list_refuses_blanks_duplicates_and_emptiness(): void
    {
        foreach ([
            [['a', '  ', 'b'], 'blank entry'],
            [['a', 'b', 'a'], "repeats 'a'"],
            [[], 'cannot be empty'],
            [[['nested']], 'list of text'],
            [['a' => 1], 'expects a list'],
        ] as [$input, $expected]) {
            try {
                $this->list()->cast($input);
                $this->fail("Expected {$expected} to be refused.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }
        }
    }
}
