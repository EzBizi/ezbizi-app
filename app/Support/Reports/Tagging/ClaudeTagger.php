<?php

namespace App\Support\Reports\Tagging;

use Anthropic\Client;

/**
 * Claude, through the official Anthropic PHP SDK. Structured output pins the
 * answer to the label list, so there is nothing to parse but one JSON field.
 * Effort is low: picking one of five labels does not repay deep reasoning.
 */
final class ClaudeTagger implements CallTagger
{
    public function name(): string
    {
        return 'claude';
    }

    public function configured(): bool
    {
        return filled(config('experimental_reports.tagging.claude.key'));
    }

    public function tag(string $transcript, string $question, array $labels): ?string
    {
        $client = new Client(apiKey: (string) config('experimental_reports.tagging.claude.key'));

        $message = $client->messages->create(
            model: (string) config('experimental_reports.tagging.claude.model'),
            maxTokens: 1024,
            system: CallLabels::instruction($question, $labels),
            messages: [['role' => 'user', 'content' => "Transcript:\n\n".$transcript]],
            outputConfig: [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['label' => ['type' => 'string', 'enum' => array_keys($labels)]],
                        'required' => ['label'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        );

        // A refusal carries no label; it is recorded as untagged, not guessed.
        if ($message->stopReason === 'refusal') {
            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $label = json_decode($block->text, true)['label'] ?? null;

                return is_string($label) && array_key_exists($label, $labels) ? $label : null;
            }
        }

        return null;
    }
}
