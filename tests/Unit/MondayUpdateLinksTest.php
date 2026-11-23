<?php

namespace Tests\Unit;

use App\Support\Tasks\Monday\MondayBoardReader;
use PHPUnit\Framework\TestCase;

/** Row 38 (Merav, 11-23): Monday updates whose files are OneDrive links came in as bare names. */
class MondayUpdateLinksTest extends TestCase
{
    public function test_a_link_keeps_its_url_and_plain_text_is_untouched(): void
    {
        $this->assertSame("[Seattle Balance Report 2026.xlsx](https://1drv.ms/x/a?e=K&b=1)\n\n[MA.xlsx](https://1drv.ms/x/b)", MondayBoardReader::bodyOf([
            'body' => '<p><a href="https://1drv.ms/x/a?e=K&amp;b=1" target="_blank">Seattle Balance Report 2026.xlsx</a></p> <p>'."\u{FEFF}".'<a href="https://1drv.ms/x/b">MA.xlsx</a></p>',
            'text_body' => 'Seattle Balance Report 2026.xlsx MA.xlsx',
        ]));
        $this->assertSame('hi there', MondayBoardReader::bodyOf(['body' => '<p>hi there</p>', 'text_body' => 'hi there']));
    }
}
