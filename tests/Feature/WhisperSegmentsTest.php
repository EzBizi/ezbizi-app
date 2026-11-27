<?php

namespace Tests\Feature;

use App\Services\Leads\CallTranscripts;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Tests\TestCase;

/** Field's chunks: Whisper's segments with times, and no audio left behind. */
class WhisperSegmentsTest extends TestCase
{
    public function test_segments_come_back_and_the_temp_file_is_gone(): void
    {
        $path = null;
        Process::fake(function ($p) use (&$path) {
            $cmd = (array) $p->command;
            $path = $cmd[2] ?? null;

            return Process::result(in_array('--json', $cmd, true)
                ? '[{"start_s":0.5,"end_s":2.1,"text":"Liner is cracked."}]' : 'wrong mode');
        });

        $this->assertSame([['start_s' => 0.5, 'end_s' => 2.1, 'text' => 'Liner is cracked.']], app(CallTranscripts::class)->whisperSegments('aac-bytes'));
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_failed_run_throws(): void
    {
        Process::fake(fn () => Process::result('', 'boom', 1));
        $this->expectException(RuntimeException::class);
        app(CallTranscripts::class)->whisperSegments('aac-bytes');
    }
}
