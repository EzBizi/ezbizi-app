<?php

namespace App\Support\Email;

use Illuminate\Support\Carbon;

/**
 * Microsoft Graph JSON into this application's own shapes.
 *
 * Pure: a documented payload in, a {@see MessageSummary} out. Hostile input.
 */
final class MicrosoftMessageMapper
{
    public function __construct(
        private readonly BodySanitiser $sanitiser,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function summary(array $payload): MessageSummary
    {
        [$fromName, $fromAddress] = $this->from($payload);

        return new MessageSummary(
            id: (string) ($payload['id'] ?? ''),
            threadId: isset($payload['conversationId']) ? (string) $payload['conversationId'] : null,
            subject: $this->line((string) ($payload['subject'] ?? '')),
            fromName: $fromName,
            fromAddress: $fromAddress,
            to: $this->recipients($payload['toRecipients'] ?? []),
            sentAt: $this->sentAt($payload),
            snippet: $this->line((string) ($payload['bodyPreview'] ?? '')),
            hasAttachments: (bool) ($payload['hasAttachments'] ?? false),
            unreadAtProvider: ! (bool) ($payload['isRead'] ?? false),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function detail(array $payload): MessageDetail
    {
        $summary = $this->summary($payload);
        $body    = is_array($payload['body'] ?? null) ? $payload['body'] : [];
        $html    = strtolower((string) ($body['contentType'] ?? '')) === 'html'
            ? (string) ($body['content'] ?? '')
            : '';
        $text    = strtolower((string) ($body['contentType'] ?? '')) === 'text'
            ? (string) ($body['content'] ?? '')
            : '';

        if ($html === '' && $text === '' && isset($body['content'])) {
            $html = (string) $body['content'];
        }

        $blocks = match (true) {
            $html !== '' => $this->sanitiser->sanitise($html),
            $text !== '' => $this->sanitiser->sanitiseText($text),
            default => [Block::of(
                BlockKind::Notice,
                'This message has no readable text — it is probably an attachment on its own.',
            )],
        };

        return new MessageDetail(
            summary: $summary,
            blocks: $blocks,
            attachments: $this->attachments($payload),
            imagesBlocked: $this->mentionsBlockedImage($blocks),
            sourceHtml: $html !== '' ? $html : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private function from(array $payload): array
    {
        $from = is_array($payload['from'] ?? null) ? $payload['from'] : [];
        $email = is_array($from['emailAddress'] ?? null) ? $from['emailAddress'] : [];

        return [
            $this->line((string) ($email['name'] ?? '')),
            $this->line((string) ($email['address'] ?? '')),
        ];
    }

    /**
     * @return string[]
     */
    private function recipients(mixed $raw): array
    {
        $out = [];

        foreach ((array) $raw as $one) {
            if (! is_array($one)) {
                continue;
            }

            $email = is_array($one['emailAddress'] ?? null) ? $one['emailAddress'] : [];
            $address = $this->line((string) ($email['address'] ?? ''));

            if ($address !== '') {
                $out[] = $address;
            }
        }

        return $out;
    }

    /**
     * `receivedDateTime` is when Graph received it. `sentDateTime` is the
     * sender's clock and can say 2099.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sentAt(array $payload): ?Carbon
    {
        $raw = (string) ($payload['receivedDateTime'] ?? '');

        if (trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array{name: string, mime: string, bytes: int}>
     */
    private function attachments(array $payload): array
    {
        $out = [];

        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (! is_array($attachment) || ($attachment['isInline'] ?? false) === true) {
                continue;
            }

            $name = $this->line((string) ($attachment['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $out[] = [
                'name'  => $name,
                'mime'  => $this->line((string) ($attachment['contentType'] ?? 'application/octet-stream')),
                'bytes' => (int) ($attachment['size'] ?? 0),
            ];
        }

        return $out;
    }

    /** @param  Block[]  $blocks */
    private function mentionsBlockedImage(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if ($block->kind === BlockKind::BlockedImage) {
                return true;
            }
        }

        return false;
    }

    private function line(string $value): string
    {
        $value = (string) preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value);
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', ' ', $value);

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }
}
