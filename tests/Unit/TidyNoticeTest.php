<?php

namespace Tests\Unit;

use App\Support\Automations\ActionRunner;
use PHPUnit\Framework\TestCase;

/** Jose, 2026-10-21: a push read "Missed call: +12195550101 ()". */
class TidyNoticeTest extends TestCase
{
    public function test_a_notice_reads_as_written_not_as_its_blanks(): void
    {
        $this->assertSame('Missed call: (219) 555-0101', ActionRunner::tidyNotice('Missed call:  +12195550101 ()'));
        $this->assertSame('Missed call: Pat (219) 555-0101 (NJ - GMB)', ActionRunner::tidyNotice('Missed call: Pat +12195550101 (NJ - GMB)'));
        $this->assertSame("Line one\nLine two", ActionRunner::tidyNotice("Line one []\nLine two"));
    }
}
