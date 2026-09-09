<?php

namespace App\Support\Preferences;

use InvalidArgumentException;

/**
 * What a user may choose about their own experience (§2.1 category 6).
 *
 * Definitions live in code, exactly as setting definitions do. The VALUES are
 * the user's; the schema is a platform invariant. If the list of permitted
 * options were itself editable data, nothing would validate anything and the
 * client would be rendering whatever happened to be in a JSON column.
 *
 * Kept deliberately separate from SettingRegistry. They look similar and are
 * owned by different people — an organization administrator sets the tenant's
 * configuration, and must never be able to reach in and set somebody's theme.
 * §2.1 is explicit that the categories are not to be merged into one generic
 * toggle system, and two small registries is the shape that honours it.
 */
class PreferenceRegistry
{
    /**
     * @return array<string, array{type: string, default: mixed, options?: array, label: string, help?: string}>
     */
    public function all(): array
    {
        return [
            /*
             * Light, dark, or follow the device.
             *
             * Per USER, not per organization. §12's theming decision is about an
             * organization's brand — its colours and logo — which is a different
             * question with a different owner. Somebody preferring dark mode at
             * night is not a branding decision, and an administrator setting it
             * for everybody would be absurd.
             */
            'appearance.theme' => [
                'label' => 'Theme',
                'type' => 'enum',
                'default' => 'system',

                /*
                 * The two mixed modes are one visual idea with two directions,
                 * and they are separate OPTIONS rather than a second toggle
                 * beside the theme. A "mixed?" switch plus a "which way?" switch
                 * would be two controls describing one choice, and somebody
                 * would eventually find the pair in a state that means nothing.
                 */
                'options' => ['system', 'light', 'dark', 'mixed_dark_nav', 'mixed_light_nav'],
                'help' => '"Match my device" follows whatever your phone or computer is set to. '
                    .'Mixed keeps the sidebar and the page in opposite themes.',
            ],

            /*
             * How the sidebar renders.
             *
             * A list reads faster — the eye scans labels down a column — and it
             * is what most people expect from a CRM. Blocks fit more in less
             * width and some people prefer them, so it stays available rather
             * than being an opinion imposed.
             */
            'navigation.style' => [
                'label' => 'Sidebar',
                'type' => 'enum',
                'default' => 'list',
                'options' => ['list', 'blocks'],
                'help' => 'A list shows the icon and label side by side; blocks stack them.',
            ],

            /*
             * Sidebar collapsed to icons.
             *
             * Stored rather than held in the widget, because a sidebar that
             * springs back open on every reload is a toggle nobody uses twice.
             * Separate from `navigation.style`: that is which SHAPE you prefer,
             * this is whether you want the room back right now.
             */
            'navigation.collapsed' => [
                'label' => 'Collapse the sidebar',
                'type' => 'bool',
                'default' => false,
                'help' => 'Shows icons only, giving the page its width back.',
            ],

            /*
             * How wide the expanded sidebar is, in logical pixels.
             *
             * A number rather than small/medium/large: it is set by dragging the
             * edge, so the value somebody produces is whatever they let go at.
             * Offering three named sizes would mean snapping their drag to one
             * of them, which feels broken in exactly the way a resize handle
             * must not.
             *
             * Bounds are enforced here as well as in the client. The client
             * clamps while dragging; this is what stops a hand-written API call
             * storing a width that makes the app unusable on the next load.
             */
            'navigation.width' => [
                'label' => 'Sidebar width',
                'type' => 'int',
                'default' => 220,
                'min' => 180,
                'max' => 420,
                'help' => 'Drag the edge of the sidebar to change it.',
            ],

            /*
             * The two panes on Web Management → Widgets.
             *
             * Registered rather than kept in the screen because the screen is
             * rebuilt from scratch on every website click, and the whole point
             * of dragging a pane is that it stays where it was put. Same shape
             * as `navigation.width` directly above, for the same reason: a
             * width is an int with bounds, and the bounds belong beside the
             * value rather than only in the client that happens to write it.
             *
             * Set by dragging, so neither appears in Settings — the client asks
             * for these two keys by name, the way it asks for the sidebar's.
             */
            'web.widgets.list_width' => [
                'label' => 'Website list width',
                'type' => 'int',
                'default' => 260,
                'min' => 180,
                'max' => 460,
                'help' => 'Drag the line beside the website list to change it.',
            ],

            'web.widgets.preview_width' => [
                'label' => 'Widget preview width',
                'type' => 'int',
                'default' => 360,
                'min' => 280,
                'max' => 640,
                'help' => 'Drag the line beside the preview to change it.',
            ],

            /*
             * Which part of the business you are looking at (§3 roll-ups).
             *
             * "organization", or "company:<uuid>", or "franchise:<uuid>". A
             * string rather than two preferences, because the two halves are
             * meaningless apart — a type with no id, or an id with no type, is
             * a state nothing can render.
             *
             * Validated on READ rather than on write: assignments change, and a
             * scope that was legitimate when it was saved can stop being so
             * without anybody touching it. ViewScope falls back to the whole
             * organization on anything unusable.
             */
            'navigation.scope' => [
                'label' => 'Current view',
                'type' => 'string',
                'default' => 'organization',
                'help' => 'Set by the switcher in the top bar.',
            ],

            /*
             * Per-user language (§8). Empty follows the organization setting.
             * Codes live in `ui_locales`, so a new language is a row.
             */
            'locale' => [
                'label' => 'Language',
                'type' => 'string',
                'default' => '',
                'help' => 'Leave blank to follow the organization. Login, the sidebar and Settings read this now; the rest of the app still follows later.',
            ],
        ];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * Coerce and validate one value, or refuse it.
     *
     * Refusing loudly rather than storing nonsense: a preference the client
     * cannot render is worse than one that was never saved, because the second
     * tells somebody immediately.
     */
    public function cast(string $key, mixed $value): mixed
    {
        $definition = $this->all()[$key] ?? throw new InvalidArgumentException(
            "Unknown preference '{$key}'."
        );

        return match ($definition['type']) {
            'bool' => (bool) $value,
            'int' => $this->clampInt($key, (int) $value, $definition),
            'enum' => in_array($value, $definition['options'], strict: true)
                ? $value
                : throw new InvalidArgumentException(sprintf(
                    "'%s' must be one of: %s.",
                    $key,
                    implode(', ', $definition['options']),
                )),
            default => (string) $value,
        };
    }

    /**
     * Hold an integer inside its declared range.
     *
     * Silently, rather than refusing: these are somebody's own preferences and
     * a value slightly out of bounds is a client bug or a stale field, not an
     * attack. Refusing would leave them with a UI stuck on an error they cannot
     * act on; clamping leaves them with a usable app.
     */
    private function clampInt(string $key, int $value, array $definition): int
    {
        return max(
            $definition['min'] ?? PHP_INT_MIN,
            min($definition['max'] ?? PHP_INT_MAX, $value),
        );
    }

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return array_map(fn (array $d) => $d['default'], $this->all());
    }
}
