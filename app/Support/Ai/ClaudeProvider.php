<?php

namespace App\Support\Ai;

use Anthropic\Client;
use RuntimeException;
use Throwable;

/**
 * Claude through the official Anthropic PHP SDK, the second AiProvider
 * (Jose, 2026-10-27: no single point of failure). Structured output pins the
 * answer to the caller's schema, as ClaudeTagger does for call labels.
 * AI_CLAUDE_MODEL (default claude-opus-5-5) and AI_CLAUDE_EFFORT (default low:
 * these are short, structured answers).
 */
final class ClaudeProvider implements AiProvider
{
    private const IMAGE_TYPES = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];

    public function name(): string
    {
        return 'claude';
    }

    public function configured(): bool
    {
        return filled($this->key());
    }

    public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array
    {
        $client = new Client(apiKey: (string) $this->key());

        // A photo goes in as an image block; anything else Claude cannot read.
        $images = [];
        foreach ($files as $name => $bytes) {
            $type = self::IMAGE_TYPES[strtolower(pathinfo((string) $name, PATHINFO_EXTENSION))] ?? null;
            if ($type !== null) {
                $images[] = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $type, 'data' => base64_encode($bytes)]];
            }
        }

        return array_map(function (string $prompt) use ($client, $schema, $images) {
            try {
                $message = $client->messages->create(
                    model: (string) config('services.ai.claude.model', 'claude-opus-5-5'),
                    maxTokens: 16000,
                    messages: [['role' => 'user', 'content' => [...$images, ['type' => 'text', 'text' => $prompt]]]],
                    outputConfig: [
                        'effort' => (string) config('services.ai.claude.effort', 'low'),
                        'format' => ['type' => 'json_schema', 'schema' => $schema],
                    ],
                );
            } catch (Throwable $e) {
                GrokCli::failed('claude: '.mb_substr($e->getMessage(), 0, 200));

                return new RuntimeException('Claude failed: '.mb_substr($e->getMessage(), 0, 300));
            }

            if ($message->stopReason === 'refusal') {
                return new RuntimeException('Claude declined to answer.');
            }

            foreach ($message->content as $block) {
                if ($block->type === 'text' && is_array($answer = json_decode($block->text, true))) {
                    return $answer;
                }
            }

            GrokCli::failed('claude: no structured output');

            return new RuntimeException('Claude answered without structured output.');
        }, $prompts);
    }

    private function key(): ?string
    {
        return config('services.ai.claude.key') ?: config('experimental_reports.tagging.claude.key');
    }
}
