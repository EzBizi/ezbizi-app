<?php

namespace App\Support\Reports\Tagging;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Grok, through xAI's chat completions API (OpenAI-compatible), with a strict
 * JSON schema so the answer is one label from the list.
 */
final class GrokTagger implements CallTagger
{
    public function name(): string
    {
        return 'grok';
    }

    public function configured(): bool
    {
        return filled(config('experimental_reports.tagging.grok.key'));
    }

    public function tag(string $transcript, string $question, array $labels): ?string
    {
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
}
