<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * An AI that answers a question as JSON matching a schema. GrokCli picks one
 * (GrokCli::provider) and every caller goes through GrokCli, so the AI is a
 * removable add-on: swap it, or run with none (Jose, 2026-10-27). The same
 * pattern as Reports' CallTagger, generalised.
 */
interface AiProvider
{
    /** 'grok' or 'claude', as stored in traces. */
    public function name(): string;

    /** Whether this install has it set up. */
    public function configured(): bool;

    /**
     * @param  list<string>  $prompts
     * @param  array<string, mixed>  $schema  JSON Schema every answer must match
     * @param  array<string, string>  $files  name => bytes beside the question (a photo)
     * @return list<array<string, mixed>|RuntimeException> one per prompt, in order; a failure stays with its prompt
     */
    public function askMany(array $prompts, array $schema, int $timeout, array $files = []): array;
}
