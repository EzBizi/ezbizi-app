<?php

namespace Tests\Unit;

use App\Support\Ai\NameDecision;
use PHPUnit\Framework\TestCase;

/** Rodrigo's §3 merge table, row by row (synthetic names). */
class NameDecisionTest extends TestCase
{
    public function test_the_merge_table(): void
    {
        $intro = 'self_introduction';

        $this->assertSame(['fill', 'Nora Vale'], NameDecision::decide(null, null, 'Nora Vale', $intro, false), 'blank');
        $this->assertSame(['fill', 'Nora Vale'], NameDecision::decide('Unknown', null, 'Nora Vale', $intro, false), 'placeholder');
        $this->assertSame(['fill', 'Nora Vale'], NameDecision::decide('(555) 010-2030', null, 'Nora Vale', $intro, false), 'phone only');
        $this->assertSame(['enrich', 'Nora Vale'], NameDecision::decide('Nora', null, 'Nora Vale', $intro, false), 'first name only');
        $this->assertSame(['correct', 'Nora Vale'], NameDecision::decide('Nora Vail', 'Nora Vail', 'Nora Vale', 'spelled_or_corrected', false), 'weak AI spelling, customer spelled it');
        $this->assertSame(['retain', null], NameDecision::decide('Nora Vale', null, null, $intro, false), 'empty extraction never erases');
        $this->assertSame(['retain', null], NameDecision::decide('Nora Vale', null, 'Mark', 'none', false), 'a referenced person is not a name');
        $this->assertSame(['conflict', 'Nora Vale'], NameDecision::decide('Nora Vail', null, 'Nora Vale', 'spelled_or_corrected', false), 'a person set it: flag, keep');
        $this->assertSame(['conflict', 'Ann Lee'], NameDecision::decide('Nora Vale', 'Nora Vale', 'Ann Lee', $intro, false), 'conflicting strong candidates');
        $this->assertSame(['conflict', 'Ann Lee'], NameDecision::decide('Nora', null, 'Ann Lee', $intro, true), 'shared phone renames nobody');
        $this->assertSame(['retain', null], NameDecision::decide('nora vale', null, 'Nora Vale', $intro, false), 'same name');
    }
}
