<?php

namespace Tests\Feature;

use App\Console\Commands\AiEval;
use App\Models\AiEvalCase;
use App\Models\AiTrace;
use App\Models\User;
use App\Support\Ai\GrokCli;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/** A1 Leads takeover (Jose 2026-10-16): every Grok call traced, pinned cases replayed. */
class AiTraceEvalTest extends TestCase
{
    use RefreshDatabase;

    private function grokSays(array ...$answers): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        $seq = Process::sequence();
        foreach ($answers as $a) {
            $seq->push(Process::result(json_encode(['structuredOutput' => $a])));
        }
        Process::fake(['*' => $seq]);
    }

    public function test_every_grok_call_is_traced_with_its_caller_and_answer(): void
    {
        $this->grokSays(['label' => 'booked']);

        GrokCli::ask('Transcript: hi', ['type' => 'object'], 30);

        $t = AiTrace::sole();
        $this->assertSame('Transcript: hi', $t->prompt);
        $this->assertSame(['label' => 'booked'], $t->response);
        $this->assertSame(class_basename(self::class), $t->scope, 'scope names the class that asked');
    }

    public function test_a_pinned_case_passes_on_the_right_answer_and_fails_on_a_wrong_one(): void
    {
        $this->grokSays(['label' => 'Booked', 'why' => 'x'], ['label' => 'not_booked']);
        AiEvalCase::create(['scope' => 'S', 'prompt' => 'p', 'schema' => ['type' => 'object'], 'expected' => ['label' => 'booked']]);

        $this->artisan('ezbizi:ai-eval')->assertExitCode(0);
        $this->artisan('ezbizi:ai-eval')->assertExitCode(1);
    }

    public function test_pin_takes_the_persons_answer_not_the_ais(): void
    {
        $trace = AiTrace::create(['scope' => 'S', 'prompt' => 'p', 'schema' => [], 'response' => ['label' => 'booked'], 'ms' => 1]);

        $this->artisan('ezbizi:ai-pin', ['trace' => $trace->id, 'expected' => '{"label":"not_booked"}'])->assertExitCode(0);
        $this->artisan('ezbizi:ai-pin', ['trace' => $trace->id, 'expected' => 'nope'])->assertExitCode(1);

        $this->assertSame(['label' => 'not_booked'], AiEvalCase::sole()->expected);
        $this->assertSame([], AiEval::misses(['n' => 2], ['n' => 2, 'extra' => 1]));
    }

    public function test_a_photo_goes_beside_the_question_with_only_the_read_tool(): void
    {
        $this->grokSays(['total' => '88.10']);

        $this->assertSame(['total' => '88.10'], GrokCli::ask('Read bill.png', ['type' => 'object'], 30, ['bill.png' => 'PNGBYTES']));

        Process::assertRan(function ($process) {
            $cmd = (array) $process->command;
            $cwd = $cmd[array_search('--cwd', $cmd) + 1];

            return $cmd[array_search('--tools', $cmd) + 1] === 'read_file'
                && $cmd[array_search('--max-turns', $cmd) + 1] === '3'
                && str_ends_with($cwd, '/cwd');
        });
    }

    /** Desk review 2026-10-16: an access or erasure request must find traces holding that person's data. */
    public function test_a_trace_is_found_by_whose_data_it_carried(): void
    {
        $this->grokSays(['a' => 1], ['a' => 2], ['a' => 3]);
        $ana = new User;
        $ana->forceFill(['id' => '01aaaaaa-0000-7000-8000-000000000001']);
        $bo = new User;
        $bo->forceFill(['id' => '01aaaaaa-0000-7000-8000-000000000002']);

        GrokCli::about($ana, fn () => GrokCli::ask('about ana', ['type' => 'object'], 30));
        GrokCli::about([null, $bo], fn () => GrokCli::askMany(['nobody', 'about bo'], ['type' => 'object'], 30));

        $this->assertSame(['about ana'], AiTrace::personalDataAbout($ana)->pluck('prompt')->all());
        $this->assertSame(['about bo'], AiTrace::personalDataAbout($bo)->pluck('prompt')->all());
        $this->assertSame(1, AiTrace::whereNull('subject_id')->count(), 'the context ends with its closure');
    }

    /** Prod 2026-11-02: most failures were an empty answer; one retry, timeouts not retried. */
    public function test_an_empty_answer_is_asked_once_more(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        Process::fake(['*' => Process::sequence()
            ->push(Process::result(json_encode(['text' => '', 'structuredOutput' => null])))
            ->push(Process::result(json_encode(['structuredOutput' => ['ok' => true]])))]);

        $this->assertSame(['ok' => true], GrokCli::ask('q', ['type' => 'object'], 30));
        Process::assertRanTimes(fn () => true, 2);
    }
}
