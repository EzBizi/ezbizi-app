<?php

namespace App\Support\Web;

use App\Support\Branding\Branding;

/**
 * What a chat bubble and a contact form are before anybody configures them,
 * and the rules that decide what a configured one is allowed to be.
 *
 * ── Why the defaults live in code and not in a row ──
 *
 * A site with no widget row renders these. If registration wrote a row instead,
 * today's defaults would be frozen into every site ever made and improving one
 * would reach nobody who already existed.
 *
 * ── Why sanitising happens here rather than at the edge ──
 *
 * Every value below ends up inside a `<style>` block or an attribute on a page
 * we do not serve, on a host we do not run. A colour that is not a colour is a
 * CSS injection into a customer's website, authored through our admin screen —
 * so the shape is enforced where the shape is defined, once, rather than
 * wherever somebody remembers to check it.
 */
final class WidgetDefaults
{
    /** The field types a form may ask for. Anything else is not rendered. */
    public const FIELD_TYPES = ['text', 'textarea', 'email', 'tel', 'select'];

    /**
     * @param  string|null  $brand  the customer's own colour, when we know it
     * @return array<string, mixed>
     */
    /**
     * Built by `resolve()` so there is exactly one shape.
     *
     * It used to assemble chat/form/style itself, which meant a site with no
     * saved config took a different code path from one with a saved config —
     * and the day the two shapes diverged, the no-config path was missing a key
     * the other one had. A default is a saved config with nothing in it, so it
     * is now literally that.
     */
    public static function all(?string $brand = null): array
    {
        return self::resolve([], $brand);
    }

    /** @return array<string, mixed> */
    public static function chat(): array
    {
        return [
            /*
             * ── Whether this site has a live chat at all ──
             *
             * Mia, 2026-09-18: "i also want to have a toggle to turn it on or
             * off, on being the default, (by turning it off, it basically
             * disappears on the website, effectively disabling the chat)".
             *
             * ON BY DEFAULT, and that is the whole difference between this and
             * the switch that was removed on 2026-09-15. That one could be off
             * on a site nobody had configured, so every site was built
             * correctly and the bubble appeared nowhere — Jose's "get rid of
             * the switches, they should just be on". A default of true cannot
             * produce that state: the only thing that takes a chat off a
             * website is somebody deciding to.
             *
             * It is one switch rather than two because the chat is the only
             * widget that puts ITSELF on every page, through `wp_footer`. The
             * form appears where a person placed a shortcode, so taking it off
             * a site is deleting that shortcode, and a second invisible way to
             * blank something deliberately placed is exactly the failure above.
             *
             * `false` does not travel as `false`. See `published()`.
             */
            'enabled' => true,
            'title' => 'Chat with us',
            'intro' => 'We are online and ready to help.',
            'button' => 'Chat',
            'placeholder' => 'Type a message',
            'send' => 'Send',
            /*
             * Asked before the conversation rather than after it.
             *
             * A chat that opens straight into a message box produces a Lead
             * with nobody's name on it: the visitor types, closes the tab, and
             * the only way to answer is to hope they come back. Three answers,
             * asked once, make every chat somebody who can be called back.
             */
            'lead_in' => 'Quick details first, so we can get back to you if we are disconnected.',
            'name_label' => 'Your name',
            'contact_label' => 'Phone or email',
            'message_label' => 'How can we help?',
            'start' => 'Start chat',
            /*
             * Sent back the instant somebody starts a chat.
             *
             * A visitor who types a question and watches nothing happen has no
             * way to tell a business that is closed from a widget that is
             * broken, and they close the tab. This is a real message on the
             * thread rather than something the widget draws locally, so it is
             * in Leads too and staff can see exactly what the person was told.
             *
             * Empty by default, and that is not a switch in disguise — the
             * field IS the setting, and the wording is the whole feature. A
             * greeting in the wrong voice is worse than none.
             *
             * THE REASON IT WAS EMPTY IS GONE; the default has not moved. It
             * used to take every new chat out of the unreplied filter in Leads,
             * which was a defect and is fixed (072cb313 — the row is marked
             * automatic and no longer counts as somebody answering).
             *
             * What remains is not a defect and not mine to decide: switching it
             * on by default would put words nobody chose into the mouth of
             * every business on the estate, at the next deploy, in EzBizi's
             * voice rather than theirs. Jose asked for a greeting he could set,
             * not for one. So it stays empty until somebody types into it, and
             * turning it on is now free of consequences.
             */
            'auto_reply' => '',
            'error' => 'Sorry — that did not send. Please try again.',
            'away' => 'We are not at the desk right now, but leave a message and we will come back to you.',
            'position' => 'bottom-right',
            /*
             * ── When the bubble is on the page at all ──
             *
             * Jose, 2026-09-17: "it needs to be able to be set so it works only
             * on certain work hours, cause if no one is there to answer it's
             * bad ... on work hours livechat should appear on the websites and
             * outside, it should disappear."
             *
             * EMPTY MEANS ALWAYS ON, and that is the whole default. Every site
             * on the estate has a chat today; shipping a schedule that defaults
             * to office hours would take the bubble off a hundred websites at
             * the next plugin update, at whatever hour the update landed, with
             * nothing logged. Narrowing is the direction nobody notices.
             *
             * SEVEN ENTRIES OR NONE. A partial week would need a rule for the
             * days it does not mention, and either answer is a guess about
             * somebody's business.
             *
             * NOT the `hours` SEO field, though that is the obvious reuse and I
             * looked at it first. That field is a STRING — `parseWeek` reads
             * back what the grid wrote and returns null for anything else — so
             * a customer who types "By appointment only" into their published
             * hours would silently lose their chat. A gate that fails on free
             * text is a gate that fails on the businesses least like a shop.
             */
            'hours' => [],
            /*
             * The zone the schedule is read in, and it must be the BUSINESS's.
             *
             * A visitor in Berlin looking at a Florida chimney sweep is not who
             * decides whether somebody is at the desk in Orlando. The browser's
             * own zone is the one thing that must never be used here.
             *
             * An IANA name rather than an offset, because an offset is wrong
             * for half the year: America/New_York is -5 in January and -4 in
             * July, and a business that set 8–5 in winter would answer 9–6 all
             * summer. `Intl.DateTimeFormat` takes this name directly in the
             * browser, so the plugin needs no library and no table.
             */
            'timezone' => '',
        ];
    }

    /**
     * A chat schedule, or `[]` meaning always on.
     *
     * Seven entries, Monday first, each `closed` or an `open`/`close` pair of
     * `HH:MM` strings. Anything that is not exactly that shape becomes `[]` —
     * FAIL OPEN, deliberately. A malformed schedule showing the bubble is a
     * business answering chats outside its hours; a malformed schedule hiding
     * it is a business losing every enquiry and finding out from nobody.
     *
     * @param  mixed  $raw
     * @return array<int, array<string, mixed>>
     */
    private static function hours($raw): array
    {
        if (! is_array($raw) || count($raw) !== 7) {
            return [];
        }

        $out = [];

        foreach (array_values($raw) as $day) {
            if (! is_array($day)) {
                return [];
            }

            if (! empty($day['closed'])) {
                $out[] = ['closed' => true, 'open' => '00:00', 'close' => '00:00'];

                continue;
            }

            $open = self::clock($day['open'] ?? '');
            $close = self::clock($day['close'] ?? '');

            if ($open === null || $close === null) {
                return [];
            }

            $out[] = ['closed' => false, 'open' => $open, 'close' => $close];
        }

        return $out;
    }

    /** `HH:MM` on a 24-hour clock, or null. `8:5` and `24:00` are both null. */
    private static function clock($raw): ?string
    {
        if (! is_string($raw) || preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /**
     * An IANA zone name PHP itself knows, or ''.
     *
     * Checked against `DateTimeZone::listIdentifiers()` rather than by pattern,
     * because the consumer is `Intl.DateTimeFormat` in a browser and a name it
     * does not know throws a RangeError on somebody's website. "EST" and
     * "Florida" both look plausible and neither is a zone this may send.
     */
    private static function zone($raw): string
    {
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        static $known = null;
        $known ??= array_flip(\DateTimeZone::listIdentifiers());

        return isset($known[$raw]) ? $raw : '';
    }

    /** @return array<string, mixed> */
    public static function form(): array
    {
        return [
            'button' => 'Send',
            'thanks' => 'Thank you — we will be in touch shortly.',
            'error' => 'Sorry — that did not send. Please try again.',
            'fields' => [
                ['label' => 'Name', 'type' => 'text', 'required' => true],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
                ['label' => 'Phone', 'type' => 'tel', 'required' => false],
                ['label' => 'How can we help?', 'type' => 'textarea', 'required' => true],
            ],
        ];
    }

    /**
     * The look, shared by both widgets.
     *
     * `font` defaults to inherit because a widget that brings its own typeface
     * to somebody's website is a widget that looks pasted on. A customer who
     * wants a specific one can say so.
     */
    public static function style(?string $brand = null): array
    {
        /*
         * The customer's colour when we know it, and a neutral when we do not.
         *
         * These render on the CUSTOMER's website as the customer's tool, so
         * defaulting to EzBizi's own palette would make every contact form look
         * like an EzBizi advert until somebody noticed — and the person who
         * would notice is the one least likely to open this screen. Jose's
         * decision, relayed by Marketing on 2026-09-08.
         *
         * #0b5cad survives as the FALLBACK, for the reason UI/UX measured when
         * it was the default: it is nobody's brand and reads 6.67:1 on white,
         * so a customer whose colour we do not know gets something legible
         * rather than something unstyled.
         *
         * on_accent is DERIVED, never a constant. A brand colour arriving as a
         * default drags the text on the button with it, and a fixed white would
         * put white on yellow for anybody with a bright brand — warning them
         * about a pair they never chose.
         */
        $accent = $brand ?? Branding::FALLBACK_COLOUR;

        return [
            'accent' => $accent,
            'on_accent' => Branding::readableOn($accent),
            'text' => '#1b1b1b',
            'background' => '#ffffff',
            'font' => 'inherit',
            'radius' => 12,
        ];
    }

    /**
     * A stored config, merged over the defaults and forced back into shape.
     *
     * Nothing here trusts the input: a config can be years old, written by a
     * version of the screen that offered different keys, or edited through the
     * API by somebody trying it on.
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function resolve(array $stored, ?string $brand = null): array
    {
        $chat = array_merge(self::chat(), self::arr($stored, 'chat'));
        $form = array_merge(self::form(), self::arr($stored, 'form'));
        $style = array_merge(self::style($brand), self::arr($stored, 'style'));

        return [
            'chat' => [
                /*
                 * MEANINGFUL AGAIN as of 2026-09-18, and the only widget key
                 * that is. See `chat()` for why the chat has a switch and the
                 * form does not, and `published()` for how `false` reaches a
                 * website that is running a plugin which ignores this key.
                 *
                 * It still travels as `true` when on, because plugin 1.4.0 and
                 * earlier render nothing when it is ABSENT and those may exist
                 * outside the fleet. So "on" has to look the way it always did.
                 */
                'enabled' => self::flag($chat['enabled'] ?? true),
                'title' => self::text($chat['title'] ?? '', 80),
                'intro' => self::text($chat['intro'] ?? '', 300),
                'button' => self::text($chat['button'] ?? '', 40),
                'placeholder' => self::text($chat['placeholder'] ?? '', 80),
                'send' => self::text($chat['send'] ?? '', 40),
                'away' => self::text($chat['away'] ?? '', 300),
                'lead_in' => self::text($chat['lead_in'] ?? '', 200),
                'name_label' => self::text($chat['name_label'] ?? '', 40),
                'contact_label' => self::text($chat['contact_label'] ?? '', 40),
                'message_label' => self::text($chat['message_label'] ?? '', 60),
                'start' => self::text($chat['start'] ?? '', 40),
                'auto_reply' => self::text($chat['auto_reply'] ?? '', 500),
                'error' => self::text($chat['error'] ?? '', 200),
                'position' => in_array($chat['position'] ?? '', ['bottom-right', 'bottom-left', 'inline'], true)
                    ? $chat['position']
                    : 'bottom-right',
                /*
                 * Both or neither. A schedule with no zone cannot be read at
                 * all — "is it 08:00?" has no answer without saying where —
                 * and a zone with no schedule gates nothing. Sending half of
                 * it would leave the plugin deciding what to do with the half,
                 * which is a decision that belongs here, once.
                 */
                ...(function () use ($chat) {
                    $hours = self::hours($chat['hours'] ?? []);
                    $zone = self::zone($chat['timezone'] ?? '');

                    return $hours === [] || $zone === ''
                        ? ['hours' => [], 'timezone' => '']
                        : ['hours' => $hours, 'timezone' => $zone];
                })(),
            ],
            'form' => [
                // Always on, and not an oversight: see `chat()`.
                'enabled' => true,
                'button' => self::text($form['button'] ?? '', 40),
                'thanks' => self::text($form['thanks'] ?? '', 300),
                'error' => self::text($form['error'] ?? '', 300),
                'fields' => self::fields($form['fields'] ?? []),
            ],
            /*
             * How the RENDERED widgets look, as named bundles. Beside the chat
             * and the form rather than inside either, because they belong to
             * neither and because they inherit from the primary site by being
             * part of this config — which is the property that makes one edit
             * reach every subdomain. See `WidgetPresets`.
             */
            'presets' => WidgetPresets::resolve($stored['presets'] ?? []),
            'style' => [
                'accent' => self::colour($style['accent'] ?? '', $brand ?? Branding::FALLBACK_COLOUR),
                'on_accent' => self::colour(
                    $style['on_accent'] ?? '',
                    Branding::readableOn($brand ?? Branding::FALLBACK_COLOUR),
                ),
                'text' => self::colour($style['text'] ?? '', '#1b1b1b'),
                'background' => self::colour($style['background'] ?? '', '#ffffff'),
                'font' => self::font($style['font'] ?? ''),
                'radius' => max(0, min(40, (int) ($style['radius'] ?? 12))),
            ],
        ];
    }

    /**
     * The same config with the switched-off widgets taken OUT, for the readers.
     *
     * ── Why absence rather than `enabled: false` ──
     *
     * Because `false` does not work, and absence does, on the plugins that are
     * actually installed. Versions 1.5.0 through 1.11.0 do not read
     * `chat.enabled` at all — the switches had been removed, so there was
     * nothing to read it for — and on 2026-09-18 the entire fleet of 110 sites
     * was on 1.11.0. A flag would have been a switch that did nothing until
     * every customer pressed Update, which for the wordpress.org half is not a
     * thing anybody here can make happen (`ACTIVE-CONSTRAINTS.md` §14).
     *
     * Every plugin since 1.2.0 already draws nothing when a widget's config
     * block is MISSING: `ezbizi_widget_config()` returns empty, the shortcode
     * returns an empty string, and the `wp_footer` injector skips. That is the
     * path a site with a refused token already takes, so it is not a new
     * behaviour to trust — it is the oldest one in the plugin.
     *
     * So "off" is said in the one vocabulary every installed version speaks.
     *
     * ── Why this is separate from `resolve()` ──
     *
     * The editing screen needs the switch it is about to flip, and a payload
     * with no `chat` key cannot draw an off switch — it looks identical to a
     * site whose chat is on and unconfigured. Same split, for the same reason,
     * as `ValueResolver::forReaders` against `forEditor`.
     *
     * @param  array<string, mixed>  $resolved  output of `resolve()` or `all()`
     * @return array<string, mixed>
     */
    public static function published(array $resolved): array
    {
        if (empty($resolved['chat']['enabled'])) {
            unset($resolved['chat']);
        }

        return $resolved;
    }

    /**
     * A stored boolean, read the way a checkbox means it.
     *
     * `filter_var` rather than a cast because this arrives from JSON that the
     * client wrote and the API may be called by hand: `"false"` and `"0"` are
     * both what somebody means by off, and both are `true` to a plain cast.
     * Anything unrecognisable keeps the default rather than becoming off — a
     * malformed config must not take a working chat off a website.
     */
    private static function flag($raw, bool $default = true): bool
    {
        $read = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        return $read ?? $default;
    }

    /**
     * The fields a form asks for, capped and cleaned.
     *
     * A form with no usable field left falls back to the default set rather
     * than rendering an empty form: a page with a Send button and nothing to
     * fill in is worse than one asking the standard four.
     *
     * @param  mixed  $raw
     * @return array<int, array<string, mixed>>
     */
    private static function fields($raw): array
    {
        if (! is_array($raw)) {
            return self::form()['fields'];
        }

        $out = [];

        foreach ($raw as $field) {
            if (! is_array($field)) {
                continue;
            }

            $label = self::text($field['label'] ?? '', 80);

            if ($label === '') {
                continue;
            }

            $type = in_array($field['type'] ?? '', self::FIELD_TYPES, true) ? $field['type'] : 'text';

            $one = [
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
            ];

            if ($type === 'select') {
                $options = [];

                foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
                    $option = self::text($option, 80);

                    if ($option !== '') {
                        $options[] = $option;
                    }
                }

                // A select with nothing to select from is a text box.
                if ($options === []) {
                    $one['type'] = 'text';
                } else {
                    $one['options'] = array_slice($options, 0, 40);
                }
            }

            $out[] = $one;

            // 20 questions is already more than anybody fills in.
            if (count($out) === 20) {
                break;
            }
        }

        return $out === [] ? self::form()['fields'] : $out;
    }

    /** @param mixed $value */
    private static function text($value, int $max): string
    {
        if (! is_string($value)) {
            return '';
        }

        // Control characters have no business in a label rendered into a page.
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        return mb_substr(trim((string) $clean), 0, $max);
    }

    /**
     * A colour, or the default.
     *
     * Only `#rgb` and `#rrggbb`. Not because other CSS colours are wrong, but
     * because anything looser has to be parsed to be trusted, and this value is
     * interpolated into a stylesheet on a stranger's website.
     *
     * @param  mixed  $value
     */
    private static function colour($value, string $fallback): string
    {
        return is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) === 1
            ? strtolower($value)
            : $fallback;
    }

    /**
     * A font family list, stripped of everything that could end a declaration.
     *
     * `inherit` is both the default and the common case: the widget should look
     * like the site it is on.
     *
     * @param  mixed  $value
     */
    private static function font($value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'inherit';
        }

        $clean = preg_replace('/[^A-Za-z0-9 ,\-]/', '', $value);
        $clean = trim((string) $clean);

        return $clean === '' ? 'inherit' : mb_substr($clean, 0, 120);
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    private static function arr(array $stored, string $key): array
    {
        return isset($stored[$key]) && is_array($stored[$key]) ? $stored[$key] : [];
    }
}
