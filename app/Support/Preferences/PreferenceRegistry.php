<?php

namespace App\Support\Preferences;

use App\Support\Modules\ModuleRegistry;
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
     * The sidebar order is validated against the modules that actually exist,
     * so the registry needs to know what they are. Injected the way
     * PermissionCatalogue takes it; nothing constructs this class directly.
     */
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * A divider in a saved sidebar order.
     *
     * Dividers carry no name (Jose, 2026-09-11: "just be dividers basically"),
     * so a sentinel is the whole of what one needs to store. It may repeat --
     * a divider is a POSITION, not a thing -- which is why the duplicate rule
     * below applies to module keys only.
     */
    public const DIVIDER = '--';

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
             * One person's own "stop interrupting me", set from the bell panel.
             * Jose, 2026-10-05: "an option turn notifications on or off
             * (individually for each user, something they can inside the
             * notification button sidebar)".
             *
             * Off silences the ALERTS — the pop-up on a computer, the push to
             * a phone, the email — and deliberately not the bell itself: the
             * switch lives inside the bell's panel, so a person who turns it
             * off is looking at the very list that keeps filling, and can
             * catch up there when they choose. Nothing is lost by muting.
             * NotificationDispatcher reads it per recipient; the client reads
             * it for its own pop-ups.
             */
            'notifications.alerts' => [
                'label' => 'Notification alerts',
                'type' => 'bool',
                'default' => true,
                'help' => 'Off stops pop-ups, phone pushes and emails for you. '
                    .'Notifications still collect in the bell.',
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
            /*
             * Whether the customer panel on the dial pad stays open.
             *
             * Registered rather than kept in the screen for the same reason as
             * the two widths below it, and Jose reported the same symptom: the
             * pin worked, and leaving Communication and coming back lost both
             * the panel and the pin. A tab's State goes when the tab does, so
             * a "keep this open" that lives there means "keep this open until
             * you look at something else", which is the opposite of what the
             * pin is for.
             *
             * Set by clicking the pin, so like the widths it is not something
             * anybody picks from a list.
             */
            /*
             * The order somebody dragged their sidebar into, with dividers.
             *
             * Per-user by the same reasoning as the pin below: it follows a
             * person to another machine, needs no migration, and is set by an
             * interaction rather than picked from a list -- which is why it has
             * `help` pointing at where it is set rather than an editor here.
             *
             * Rail 1 ONLY. Settings, Suggestions and Development are separate
             * NavigationRails with their own index spaces, so they are not in
             * this list and cannot be dragged into it. Jose asked for them to
             * be fixed; the structure already had that.
             *
             * Empty means declaration order in `module_screens.dart`, which is
             * what everybody has until they touch it.
             */
            'navigation.order' => [
                'label' => 'Sidebar order',
                'type' => 'order',
                'default' => [],
                'help' => 'Drag the sidebar into the order you want.',
            ],

            /*
             * Letting a person know there's a phone app (UI queue 67; Jose,
             * 2026-10-08: "so people who are using the web or desktop know
             * there's an app, something seamless"). The phone app sets
             * apps.phone_used each time it starts, so the Dashboard's one-time
             * card never asks somebody who has it; No thanks sets
             * apps.phone_card_dismissed, which also hides the banner in a
             * phone's browser. Per person, so a choice holds on every device.
             */
            'apps.phone_used' => [
                'label' => 'Has used the phone app',
                'type' => 'bool',
                'default' => false,
                'help' => 'Set by the phone app itself.',
            ],
            'apps.phone_card_dismissed' => [
                'label' => 'No thanks to the phone app',
                'type' => 'bool',
                'default' => false,
                'help' => 'Set by No thanks on the Dashboard card or the phone banner.',
            ],

            /*
             * Which version of each tab's walkthrough this person has been
             * through or declined (UI queue 81), keyed by module. Per person,
             * so it follows them to the phone; a release that raises a tab's
             * version offers only the new steps.
             */
            'help.tours_seen' => [
                'label' => 'Walkthroughs seen',
                'type' => 'versions',
                'default' => [],
                'help' => "Set by finishing or declining a tab's walkthrough.",
            ],

            'phone.customer_panel_pinned' => [
                'label' => 'Keep the customer panel open',
                'type' => 'bool',
                'default' => false,
                'help' => 'Click the pin on the customer panel to change it.',
            ],

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
             * The list beside a lead's conversation, and the people list on
             * HR → Records. Jose, 2026-09-29, on the first: "it resets after
             * u leave the tab" -- the pin's symptom above, for the pin's
             * reason, fixed the same way.
             *
             * Leads' is a share of the width rather than pixels, because the
             * conversation is the pane that should grow with the window; 30
             * is his "default should be 30 - 70". The client still keeps both
             * panes above their pixel floors whatever share is stored.
             */
            'leads.list_percent' => [
                'label' => 'Leads list width',
                'type' => 'int',
                'default' => 30,
                'min' => 15,
                'max' => 70,
                'help' => 'Drag the line beside the leads list to change it.',
            ],

            'hr.records.list_width' => [
                'label' => 'People list width',
                'type' => 'int',
                'default' => 300,
                'min' => 240,
                'max' => 900,
                'help' => 'Drag the line beside the list of people to change it.',
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
     *
     * Two branches below CORRECT instead, and they are not exceptions to that
     * rule so much as the other half of it. The question is not how wrong the
     * value is, it is **who is responsible for it**:
     *
     *   - A value a PERSON picked is refused. An `enum` outside its options
     *     means somebody sent a theme this build has no name for, and the only
     *     honest answer is to say so.
     *   - A value the SYSTEM outgrew is corrected. `clampInt` clamps a width
     *     whose bounds we have since tightened; `cleanOrder` drops a module
     *     key that named a real module until we removed it. Nobody typed
     *     either one, and refusing would leave somebody looking at an error
     *     about a decision that was ours -- permanently unable to save their
     *     own sidebar, over a module they never chose to lose.
     *
     * Recorded here because the rule above, read alone, says to tighten both
     * of them. (Raised by the deploy desk, 2026-09-11.)
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
            'order' => $this->cleanOrder($value),
            'versions' => $this->cleanVersions($value),
            default => (string) $value,
        };
    }

    /**
     * A sidebar order: module keys in the order somebody dragged them into,
     * with [DIVIDER] wherever they put a rule.
     *
     * CORRECTED rather than refused, following `clampInt` below. The docblock
     * above is about nonsense the client cannot render; this is different. A
     * key that names no module is not something anybody typed -- it is a module
     * we removed since they saved. Refusing would leave that person unable to
     * save their sidebar ever again, over a module that no longer exists.
     *
     * So: unknown keys are dropped, duplicates keep their first position, and
     * anything that is not a string goes. What survives is always renderable.
     *
     * NOT normalised beyond that. A leading divider, or two in a row, is
     * suppressed when the sidebar DRAWS -- it has to be anyway, because the
     * search filter produces both from an order that was fine when saved.
     * Normalising here as well would be a second rule to keep in step.
     */
    private function cleanOrder(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $known = array_keys($this->modules->all());
        $seen = [];
        $out = [];

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if ($entry === self::DIVIDER) {
                $out[] = $entry;

                continue;
            }
            if (! in_array($entry, $known, strict: true) || isset($seen[$entry])) {
                continue;
            }
            $seen[$entry] = true;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Module key => a walkthrough version. Corrected rather than refused, as
     * the sidebar order is: a key that is not a plausible module key, or a
     * version that is not a small whole number, is dropped, and what is left
     * is always readable.
     */
    private function cleanVersions(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $version) {
            if (is_string($key) && preg_match('/^[a-z0-9_]{1,64}$/', $key) && is_int($version) && $version >= 0 && $version <= 10000) {
                $out[$key] = $version;
            }
        }

        // Empty goes out as [] (PHP has no empty object here); the client
        // reads either as nothing seen.
        return array_slice($out, 0, 200, preserve_keys: true);
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
