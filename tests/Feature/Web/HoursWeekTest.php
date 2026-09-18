<?php

namespace Tests\Feature\Web;

use App\Support\Web\HoursWeek;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The published hours string, read back into seven days.
 *
 * ── THIS TABLE IS A CONTRACT WITH DART, NOT A SET OF EXAMPLES ──
 *
 * `parseWeek` in `client/lib/screens/web/hours.dart` is the first
 * implementation of this format and `HoursWeek` is the second. Two parsers that
 * disagree put one week on the SEO screen and a different one on the customer's
 * website, and nothing would report it — both sides would look right to the
 * person looking at them.
 *
 * So the same cases live in `client/test/web_hours_test.dart` under the same
 * names. Add a case here, add it there.
 */
class HoursWeekTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function strings(): array
    {
        return [
            // What the grid writes, in the arrangements it writes them.
            'a collapsed working week' => ['Mon–Fri 8:00 AM – 5:00 PM · Sat 9:00 AM – 1:00 PM · Sun closed', true],
            'seven separate days' => [
                'Mon 8:00 AM – 5:00 PM · Tue 8:00 AM – 5:00 PM · Wed 8:00 AM – 5:00 PM · '
                .'Thu 8:00 AM – 5:00 PM · Fri 8:00 AM – 5:00 PM · Sat 8:00 AM – 5:00 PM · '
                .'Sun 8:00 AM – 5:00 PM',
                true,
            ],
            'one span for the whole week' => ['Mon–Sun 12:00 AM – 11:59 PM', true],
            'shut all week' => ['Mon–Sun closed', true],
            'midnight and noon' => ['Mon–Sun 12:00 AM – 12:00 PM', true],

            // Somebody's own sentence. The important case: null, not a guess.
            'a sentence' => ['By appointment, November to March', false],
            'empty' => ['', false],
            'whitespace' => ['   ', false],

            // Nearly right, and therefore the ones worth pinning.
            'a hyphen instead of an en dash' => ['Mon-Fri 8:00 AM – 5:00 PM', false],
            'a day missing' => ['Mon–Sat 8:00 AM – 5:00 PM', false],
            'a day named twice' => ['Mon–Fri 8:00 AM – 5:00 PM · Fri closed · Sat–Sun closed', false],
            'a backwards span' => ['Fri–Mon 8:00 AM – 5:00 PM', false],
            'a 24-hour clock' => ['Mon–Sun 08:00 – 17:00', false],
            'a thirteenth hour' => ['Mon–Sun 13:00 PM – 5:00 PM', false],
            'no meridiem' => ['Mon–Sun 8:00 – 5:00 PM', false],
            'a full day name' => ['Monday–Friday 8:00 AM – 5:00 PM', false],
            'an empty chunk' => ['Mon–Fri 8:00 AM – 5:00 PM · · Sat–Sun closed', false],
        ];
    }

    #[DataProvider('strings')]
    public function test_only_what_the_grid_wrote_is_read_back(string $text, bool $parses): void
    {
        $week = HoursWeek::parse($text);

        if (! $parses) {
            $this->assertNull($week, 'This must not be read as a week: '.$text);

            return;
        }

        $this->assertIsArray($week);
        $this->assertCount(7, $week);
        $this->assertSame(
            ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            array_column($week, 'day'),
        );
    }

    /** The values, not just the shape — a parser can be seven-long and wrong. */
    public function test_a_week_is_read_into_the_right_days(): void
    {
        $week = HoursWeek::parse('Mon–Fri 8:00 AM – 5:00 PM · Sat 9:00 AM – 1:00 PM · Sun closed');

        $this->assertSame(['day' => 'Mon', 'closed' => false, 'open' => '08:00', 'close' => '17:00'], $week[0]);
        $this->assertSame(['day' => 'Fri', 'closed' => false, 'open' => '08:00', 'close' => '17:00'], $week[4]);
        $this->assertSame(['day' => 'Sat', 'closed' => false, 'open' => '09:00', 'close' => '13:00'], $week[5]);
        $this->assertSame(['day' => 'Sun', 'closed' => true, 'open' => '00:00', 'close' => '00:00'], $week[6]);
    }

    /**
     * Noon and midnight, which is where a 12-hour clock goes wrong.
     *
     * 12 AM is 00:00 and 12 PM is 12:00. The naive `+12 for PM` gives 24:00 for
     * noon, and the naive `leave AM alone` gives 12:00 for midnight — both of
     * which would publish a business as open when it is shut.
     */
    public function test_twelve_is_handled_at_both_ends(): void
    {
        $week = HoursWeek::parse('Mon–Sun 12:00 AM – 12:00 PM');

        $this->assertSame('00:00', $week[0]['open']);
        $this->assertSame('12:00', $week[0]['close']);
    }

    /** Pasted out of a browser, non-breaking space and all. */
    public function test_a_non_breaking_space_around_the_edges_survives(): void
    {
        $this->assertNotNull(HoursWeek::parse("\u{00A0}Mon–Sun closed\u{00A0}"));
    }
}
