<?php

namespace App\Support\Reports\Tagging;

use App\Support\Ai\GrokCli;
use Illuminate\Support\Facades\Http;
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
        return filled(config('experimental_reports.tagging.grok.key')) || GrokCli::configured();
    }

    public function tag(string $transcript, string $question, array $labels): ?string
    {
        if (GrokCli::configured()) {
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
        $label = GrokCli::ask(
            CallLabels::instruction($question, $labels)
                ."\n\nAnswer with the label only, as the JSON asked for.\n\nTranscript:\n\n".$transcript,
            [
                'type' => 'object',
                'properties' => ['label' => ['type' => 'string', 'enum' => array_keys($labels)]],
                'required' => ['label'],
                'additionalProperties' => false,
            ],
        )['label'] ?? null;

        return is_string($label) && array_key_exists($label, $labels) ? $label : null;
    }
}
