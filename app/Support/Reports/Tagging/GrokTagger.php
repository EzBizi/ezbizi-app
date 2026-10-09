<?php

namespace App\Support\Reports\Tagging;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Grok, with a strict JSON schema so the answer is one label from the list.
 *
 * Two ways to reach it. With an API key, xAI's chat completions API. With
 * REPORT_TAG_GROK_CLI_HOME, the Grok Build CLI signed in to Jose's own Grok
 * subscription on the box (Jose, 2026-10-09: "Yes set it up", told of xAI's
 * consumer terms). The CLI is run headless and with nothing to act with: no
 * tools, no web, no subagents, one turn, in an empty directory. The
 * transcript goes in a file, never on the command line, where `ps` shows it.
 */
final class GrokTagger implements CallTagger
{
    public function name(): string
    {
        return 'grok';
    }

    public function configured(): bool
    {
        return filled(config('experimental_reports.tagging.grok.key')) || filled(config('experimental_reports.tagging.grok.cli_home'));
    }

    public function tag(string $transcript, string $question, array $labels): ?string
    {
        if (filled(config('experimental_reports.tagging.grok.cli_home'))) {
            return $this->viaCli($transcript, $question, $labels);
        }

        $response = Http::withToken((string) config('experimental_reports.tagging.grok.key'))
            ->acceptJson()
            ->timeout(60)
            ->post('https://api.x.ai/v1/chat/completions', [
                'model' => (string) config('experimental_reports.tagging.grok.model'),
                'temperature' => 0,
                'messages' => [
                    ['role' => 'system', 'content' => CallLabels::instruction($question, $labels)],
                    ['role' => 'user', 'content' => "Transcript:\n\n".$transcript],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'call_label',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'properties' => ['label' => ['type' => 'string', 'enum' => array_keys($labels)]],
                            'required' => ['label'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
            ]);

        if ($response->failed()) {
            // Status only: the body can echo the request back.
            throw new RuntimeException('xAI refused the request ('.$response->status().').');
        }

        $label = json_decode((string) $response->json('choices.0.message.content'), true)['label'] ?? null;

        return is_string($label) && array_key_exists($label, $labels) ? $label : null;
    }

    private function viaCli(string $transcript, string $question, array $labels): ?string
    {
        $home = (string) config('experimental_reports.tagging.grok.cli_home');
        $work = storage_path('app/grok-'.bin2hex(random_bytes(6)));
        File::ensureDirectoryExists($work.'/cwd', 0700);

        try {
            File::put($work.'/prompt.txt', CallLabels::instruction($question, $labels)
                ."\n\nAnswer with the label only, as the JSON asked for.\n\nTranscript:\n\n".$transcript);

            $result = Process::env(['HOME' => $home])
                ->timeout(120)
                ->run([
                    $home.'/.grok/bin/grok',
                    '--prompt-file', $work.'/prompt.txt',
                    '--json-schema', json_encode([
                        'type' => 'object',
                        'properties' => ['label' => ['type' => 'string', 'enum' => array_keys($labels)]],
                        'required' => ['label'],
                        'additionalProperties' => false,
                    ]),
                    '--max-turns', '1',
                    '--disable-web-search',
                    '--no-subagents',
                    '--tools', '',
                    '--cwd', $work.'/cwd',
                ]);
        } finally {
            File::deleteDirectory($work);
        }

        if (! $result->successful()) {
            // Loud, and without the prompt: an expired sign-in must not read
            // as calls that simply had no label. The error output is the
            // CLI's own, cut short.
            throw new RuntimeException('The Grok CLI failed (exit '.$result->exitCode().'): '
                .mb_substr(trim($result->errorOutput() ?: $result->output()), 0, 300));
        }

        // `structuredOutput.label` (measured on the box, grok 1.0.50); else
        // wherever the JSON put it. Checked against the list, so only one of
        // ours can come back.
        $label = json_decode($result->output(), true)['structuredOutput']['label'] ?? null;

        if (! is_string($label)) {
            if (! preg_match('/"label"\s*:\s*"([a-z_]+)"/', str_replace('\\"', '"', $result->output()), $m)) {
                throw new RuntimeException('The Grok CLI answered without a label: '.mb_substr(trim($result->output()), 0, 200));
            }

            $label = $m[1];
        }

        return array_key_exists($label, $labels) ? $label : null;
    }
}
