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
                'label'   => 'Theme',
                'type'    => 'enum',
                'default' => 'system',
                'options' => ['system', 'light', 'dark'],
                'help'    => '"Match my device" follows whatever your phone or computer is set to.',
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
                'label'   => 'Sidebar',
                'type'    => 'enum',
                'default' => 'list',
                'options' => ['list', 'blocks'],
                'help'    => 'A list shows the icon and label side by side; blocks stack them.',
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
                'label'   => 'Collapse the sidebar',
                'type'    => 'bool',
                'default' => false,
                'help'    => 'Shows icons only, giving the page its width back.',
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
            'int'  => (int) $value,
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

    /** @return array<string, mixed> */
    public function defaults(): array
    {
        return array_map(fn (array $d) => $d['default'], $this->all());
    }
}
