<?php

namespace App\Support\Newsletter;

use App\Models\NewsletterSectionVersion;

/**
 * Saved sections (P1b, 2026-11-18).
 *
 * A linked use is a `section_ref` block, `{section_id, version}`. It is
 * expanded here, at render time, into the blocks of THAT version, never the
 * latest: a newsletter changes only when somebody presses "Update to latest"
 * on it, and a sent one never does. A reference whose version cannot be
 * found renders nothing rather than guessing.
 *
 * The starters are code, not rows: five A1-style sections every company is
 * offered under "Starters", inserted as copies.
 */
final class NewsletterSections
{
    /** Blocks a saved section may hold: no nested references, no unknown types. */
    public const TYPES = ['heading', 'text', 'image', 'button', 'divider', 'spacer', 'columns'];

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function expand(array $blocks): array
    {
        $ids = [];
        foreach ($blocks as $b) {
            if (is_array($b) && ($b['type'] ?? null) === 'section_ref' && is_string($b['section_id'] ?? null)) {
                $ids[] = $b['section_id'];
            }
        }
        $found = [];
        if ($ids !== []) {
            NewsletterSectionVersion::query()
                ->whereIn('newsletter_section_id', array_values(array_unique($ids)))
                ->get(['newsletter_section_id', 'version', 'blocks'])
                ->each(function (NewsletterSectionVersion $v) use (&$found) {
                    $found[$v->newsletter_section_id.'@'.$v->version] = (array) $v->blocks;
                });
        }

        $out = [];
        foreach ($blocks as $b) {
            if (! is_array($b)) {
                continue;
            }
            if (($b['type'] ?? null) !== 'section_ref') {
                $out[] = $b;

                continue;
            }
            foreach ($found[($b['section_id'] ?? '').'@'.(int) ($b['version'] ?? 0)] ?? [] as $inner) {
                if (is_array($inner) && ($inner['type'] ?? null) !== 'section_ref') {
                    $out[] = $inner;
                }
            }
        }

        return $out;
    }

    /**
     * Only plain blocks are kept in a section: a nested reference would let
     * one section silently change another.
     *
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    public static function cleanBlocks(array $blocks): array
    {
        return array_values(array_filter($blocks,
            fn ($b) => is_array($b) && in_array($b['type'] ?? null, self::TYPES, true)));
    }

    /** @return list<array{key: string, name: string, blocks: list<array<string, mixed>>}> */
    public static function starters(): array
    {
        return [
            ['key' => 'seasonal-intro', 'name' => 'Seasonal intro', 'blocks' => [
                ['type' => 'heading', 'text' => 'Get your home ready for the season', 'size' => 26, 'align' => 'center'],
                ['type' => 'text', 'text' => 'As the weather turns, now is the best time to book your inspection before the busy season fills the calendar.', 'align' => 'center'],
            ]],
            ['key' => 'service-offer', 'name' => 'Service offer', 'blocks' => [
                ['type' => 'heading', 'text' => 'This month only: 10% off a cleaning', 'size' => 22],
                ['type' => 'text', 'text' => 'Mention this email when you book. Offer valid for new appointments made this month.'],
                ['type' => 'button', 'text' => 'Claim the offer', 'url' => '', 'align' => 'left'],
            ]],
            ['key' => 'safety-checklist', 'name' => 'Safety checklist', 'blocks' => [
                ['type' => 'heading', 'text' => 'Your quick safety checklist', 'size' => 20],
                ['type' => 'text', 'rich' => true, 'text' => '<ul><li>Test smoke and carbon monoxide alarms</li><li>Clear leaves and nests from the cap</li><li>Look for cracks or loose bricks</li><li>Book a yearly inspection</li></ul>'],
            ]],
            ['key' => 'customer-review', 'name' => 'Customer review', 'blocks' => [
                ['type' => 'divider'],
                ['type' => 'text', 'text' => '"Fast, clean and friendly. The technician explained everything."', 'size' => 18, 'align' => 'center'],
                ['type' => 'text', 'text' => '- A happy customer', 'size' => 13, 'align' => 'center'],
                ['type' => 'divider'],
            ]],
            ['key' => 'booking-cta', 'name' => 'Booking call to action', 'blocks' => [
                ['type' => 'heading', 'text' => 'Ready to book?', 'size' => 22, 'align' => 'center'],
                ['type' => 'button', 'text' => 'Book an appointment', 'url' => '', 'align' => 'center', 'mobile' => ['button_full_width' => true]],
            ]],
        ];
    }
}
