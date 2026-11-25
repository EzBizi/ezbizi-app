<?php

namespace Tests\Unit;

use App\Support\NotesMarkup;
use PHPUnit\Framework\TestCase;

class NotesMarkupTest extends TestCase
{
    public function test_plain_strips_only_the_three_tags(): void
    {
        $this->assertSame('Price $90 or ask <br> 2<3', NotesMarkup::plain('Price <b>$90</b> or <i><u>ask</u></i> <br> 2<3'));
        $this->assertNull(NotesMarkup::plain(null));
    }
}
