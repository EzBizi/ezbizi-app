<?php

namespace Tests\Unit;

use App\Support\Audio\PlayableAudio;
use PHPUnit\Framework\TestCase;

/**
 * Which recordings are converted for the Windows player.
 *
 * The positive case is the real first bytes of a Google Local Services call
 * recording, captured on production 2026-09-28 (`ffe380c4...`). Everything
 * else must pass through untouched: converting audio a player can already
 * open would cost size and time for nothing.
 */
class PlayableAudioTest extends TestCase
{
    public function test_a_google_call_recording_is_mpeg_2_5(): void
    {
        $this->assertTrue(PlayableAudio::isMpeg25(hex2bin('ffe380c400461415f0075978')));
        $this->assertTrue(PlayableAudio::isMpeg25(hex2bin('ffe380c400550c12640759c8')));
    }

    public function test_an_id3_tag_in_front_is_skipped(): void
    {
        // ID3v2.3, no flags, a 4-byte body - then the same MPEG-2.5 frame.
        $tagged = 'ID3'.hex2bin('03000000000004').'abcd'.hex2bin('ffe380c4');

        $this->assertTrue(PlayableAudio::isMpeg25($tagged));
    }

    public function test_other_audio_is_not_mistaken_for_it(): void
    {
        $this->assertFalse(PlayableAudio::isMpeg25(hex2bin('fffb9064')), 'MPEG-1 Layer III, which Windows plays');
        $this->assertFalse(PlayableAudio::isMpeg25(hex2bin('fff39064')), 'MPEG-2 Layer III, which Windows plays');
        $this->assertFalse(PlayableAudio::isMpeg25('RIFF'.str_repeat("\0", 40)), 'a WAV');
        $this->assertFalse(PlayableAudio::isMpeg25('<html><body>Access not allowed'), "Google's refusal page");
        $this->assertFalse(PlayableAudio::isMpeg25(''));
    }

    public function test_audio_that_needs_nothing_passes_through_unchanged(): void
    {
        $mp3 = hex2bin('fffb9064').str_repeat("\x55", 64);

        $this->assertSame([$mp3, 'audio/mpeg'], PlayableAudio::forEveryPlayer($mp3, 'audio/mpeg'));
    }
}
