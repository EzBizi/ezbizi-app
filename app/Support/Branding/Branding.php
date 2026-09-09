<?php

namespace App\Support\Branding;

use Illuminate\Database\Eloquent\Model;

/**
 * The two shapes a logo may be, and their exact sizes (plan §12).
 *
 * ── One list, because two would drift ────────────────────────────
 *
 * The cropper produces these dimensions and the validator refuses anything
 * else. Those are the same numbers doing two jobs, so they are written once —
 * the client reads them from /branding/shapes rather than carrying its own copy.
 *
 * ── Why fixed sizes at all ───────────────────────────────────────
 *
 * Uniformity is the whole point. Left to whatever a business happens to have on
 * disk, one company's mark arrives 3000px wide and another's 64px, and every
 * screen showing them side by side looks accidental. Fixing the output means a
 * row of company logos lines up without anybody thinking about it.
 *
 * 512 for the square, so it still looks sharp on a retina screen at the largest
 * place it appears. 1024x256 for the lockup — 4:1 is the ratio most horizontal
 * marks already sit near, so cropping to it usually costs nothing.
 */
final class Branding
{
    public const SQUARE = 'square';

    public const WIDE   = 'wide';

    /** @var array<string, array{width: int, height: int, label: string, help: string}> */
    public const SHAPES = [
        self::SQUARE => [
            'width'  => 512,
            'height' => 512,
            'label'  => 'Square',
            'help'   => 'An icon, monogram or badge. Used wherever space is tight — '
                .'the top bar, lists, anywhere a mark stands alone.',
        ],
        self::WIDE => [
            'width'  => 1024,
            'height' => 256,
            'label'  => 'Wide',
            'help'   => 'A horizontal lockup with the name beside the mark. Used on '
                .'documents and headers, where there is room to read it.',
        ],
    ];

    /**
     * PNG only.
     *
     * A logo is line art and flat colour, which is exactly what PNG is for and
     * exactly what JPEG is worst at — its artefacts cluster on hard edges, so a
     * JPEG logo arrives with a grey halo around every letter. PNG also keeps
     * transparency, without which a mark cannot sit on a dark header.
     *
     * SVG is absent for a different reason: it is a document that can carry
     * script, and it would be served from our own origin.
     */
    public const MIME = 'png';

    /**
     * The same decision as a response header.
     *
     * `MIME` is an EXTENSION, because that is what Laravel's `mimes:` rule and
     * the client's file picker both want. A `Content-Type` is not an extension,
     * and writing `image/png` at the point of serving would be a second place
     * the format is decided. Derived, so there is still only one.
     */
    public static function contentType(): string
    {
        return 'image/'.self::MIME;
    }

    /** The shape whose dimensions these are, or null if they match neither. */
    public static function shapeOf(int $width, int $height): ?string
    {
        foreach (self::SHAPES as $name => $shape) {
            if ($width === $shape['width'] && $height === $shape['height']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * A token that changes whenever the mark this subject DISPLAYS changes.
     *
     * ── Why it cannot just be the subject's own timestamp ────────
     *
     * A company with no logo of its own shows the organization's. So when the
     * organization's mark is replaced or removed, that company's picture
     * changes while nothing about the company's own row does.
     *
     * The client used to refetch only when a subject's `has_logo` flipped —
     * which is false before and false after for every inheriting company. So
     * clearing the organization's logo left four companies painting an image
     * that no longer existed, and nothing would correct them until a reload.
     *
     * This resolves to whichever subject actually provides the bytes, so the
     * token moves for every badge whose picture moved, and for no others.
     */
    public static function version(?Model $subject, Model $organization): string
    {
        $effective = ($subject?->logo_path !== null) ? $subject : $organization;

        if ($effective->logo_path === null) {
            return 'none';
        }

        return (string) ($effective->updated_at?->getTimestamp() ?? 0);
    }

    /**
     * What represents a business that has told us no colour.
     *
     * Deliberately nobody's brand, and measured rather than chosen: 6.67:1 on
     * white, which is why it survives as the fallback now that a real brand
     * colour can be the default. A fallback that inherited nothing would not be
     * neutral, it would be unstyled.
     *
     * It lives here rather than in the widget that renders it because a second
     * consumer already exists — the picker draws this exact colour for the
     * not-set state, which is every customer today — and two copies of a
     * fallback are two things to disagree.
     */
    public const FALLBACK_COLOUR = '#0b5cad';

    /**
     * The customer's own colour, resolved the way their logo is.
     *
     * The company's if it has one, else the organization's, else null — and
     * null means we genuinely do not know it, which is different from a colour
     * somebody chose. Callers that need a colour regardless supply their own
     * fallback rather than being handed one that pretends to be the customer's.
     *
     * Inherited at read time, never copied: changing the organization's changes
     * every company that never set its own, exactly as the logo does.
     */
    public static function colour(?Model $subject, ?Model $organization): ?string
    {
        foreach ([$subject, $organization] as $candidate) {
            $value = $candidate?->brand_colour;

            if (is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1) {
                return strtolower($value);
            }
        }

        return null;
    }

    /**
     * Black or white, whichever a person can actually read on [$hex].
     *
     * Because a brand colour arriving as a default drags a second colour with
     * it: the text ON the button. Defaulting that to white would put white on
     * yellow for anybody whose brand is bright, and the screen would warn about
     * a pair the customer never chose. Derived, so the pair is legible by
     * construction and the warning is reserved for choices somebody made.
     *
     * ── Why the dark is #000000 and not a softer near-black ──
     *
     * 0.179 is the crossover for PURE black. #111111 has luminance 0.0056, not
     * zero, which moves the real crossover to 0.1916 — so a band of ordinary
     * mid-tone brands would get the dark text while white was still the better
     * of the two, AND the best contrast available at the crossover drops below
     * 4.5:1. #00838F, an unremarkable teal, reads 4.18:1 on #111111 and 4.64:1
     * on #000000.
     *
     * That matters because of what this function is FOR. A derived pair is one
     * the customer never chose; if it can fall under 4.5:1 then our own screen
     * warns them about our own choice, in their name. With pure black the worst
     * case anywhere is 4.58:1, so a derived pair can never trip that warning
     * for any colour a customer types. With #111111 it can.
     *
     * Found by the UI/UX session, who also supplied the failing colour.
     */
    public static function readableOn(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return '#ffffff';
        }

        $channel = static function (int $byte): float {
            $c = $byte / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        $luminance = 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
            + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
            + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));

        return $luminance > 0.179 ? '#000000' : '#ffffff';
    }

    /** "512x512 or 1024x256" — for a refusal that says what would work. */
    public static function expected(): string
    {
        return implode(' or ', array_map(
            fn (array $s) => "{$s['width']}x{$s['height']}",
            self::SHAPES,
        ));
    }
}
