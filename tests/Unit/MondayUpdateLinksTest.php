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

    public function test_a_monday_person_link_is_a_plain_at_name(): void
    {
        $this->assertSame('@Cristine Hicale tech sup ok [x](https://x.com/a)', MondayBoardReader::bodyOf([
            'body' => '<p><a href="https://a-1group.monday.com/users/119890962-cristine-hicale">@Cristine Hicale</a> tech sup ok <a href="https://x.com/a">x</a></p>',
        ]));
        $this->assertSame('↳ @Angela Herrera ch [x](https://x.com)', MondayBoardReader::cleanMentions('↳ [@Angela Herrera](https://a-1group.monday.com/users/119721735) ch [x](https://x.com)'));
    }

    public function test_bold_italic_and_underline_from_monday_are_kept_as_ours(): void
    {
        $this->assertSame('<b>Call</b> the <i>owner</i> <u>first</u>', MondayBoardReader::bodyOf([
            'body' => '<p><strong>Call</strong> the <em class="x">owner</em> <u>first</u></p>', 'text_body' => 'Call the owner first',
        ]));
        $this->assertSame('plain', MondayBoardReader::bodyOf(['body' => '<p>plain<br></p>', 'text_body' => 'plain']));
    }


}
