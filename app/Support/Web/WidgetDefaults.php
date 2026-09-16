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
             * It also has a consequence nobody would choose by accident:
             * "unreplied" in Leads means `last_contact_from = CONSUMER`, so an
             * automatic answer takes every new chat OUT of the unreplied
             * filter the instant it arrives. A real enquiry would look
             * answered. Raised with the Leads module; until a conversation row
             * can say it was automatic, this stays off unless a business types
             * something into it deliberately.
             */
            'auto_reply' => '',
            'error' => 'Sorry — that did not send. Please try again.',
            'away' => 'We are not at the desk right now, but leave a message and we will come back to you.',
            'position' => 'bottom-right',
        ];
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
                 * `enabled` is sent, always true, and means nothing.
                 *
                 * Jose removed the switches on 2026-09-15: "get rid of the
                 * switches, they should just be on". Placing the widget on a
                 * page is the decision to show it, and a second invisible
                 * switch that can blank something you deliberately placed has
                 * one failure mode — the one it produced, where every site was
                 * built correctly and nothing appeared anywhere.
                 *
                 * The key stays on the wire because plugin 1.4.0 and earlier
                 * render nothing when it is absent, and those are installed on
                 * real sites. Dropping it would black out every widget on any
                 * site that had not updated yet. Remove it when no install
                 * below 1.5.0 is left; `plugin_version` comes in on every
                 * payload request, so that is a question we can answer.
                 */
                'enabled' => true,
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
            ],
            'form' => [
                // Always on; see the note above.
                'enabled' => true,
                'button' => self::text($form['button'] ?? '', 40),
                'thanks' => self::text($form['thanks'] ?? '', 300),
                'error' => self::text($form['error'] ?? '', 300),
                'fields' => self::fields($form['fields'] ?? []),
            ],
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
