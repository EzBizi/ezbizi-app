<?php

namespace App\Support\Roles;

use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleAvailability;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TabDefinition;
use Illuminate\Validation\ValidationException;

/**
 * A role's phone bottom bar (Jose, 2026-11-23): up to four {module, tab|null}
 * slots; "More" is the client's fixed fifth.
 *
 * Which modules may be on it: ROLES_TAB modules (the ones drawn as a screen
 * of their own; ROLES_OMIT, organization and settings groups are not), that
 * are available to the organization (§13) and that the role holds `.view` on.
 * A tab additionally needs its own key, `{module}.{tab->action()}`.
 *
 * Saving checks against what the role holds; reading (/me) filters against
 * what the PERSON can reach now, so a permission taken away later drops the
 * slot without rewriting the stored bar.
 */
final class MobileBar
{
    public const MAX_SLOTS = 4;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly ModuleAvailability $availability,
    ) {}

    /**
     * Validate and normalise slots against a set of held permission keys.
     *
     * @param  array<int, mixed>  $slots
     * @param  string[]  $held
     * @return array<int, array{module: string, tab: ?string}>
     */
    public function validated(array $slots, array $held, $organization): array
    {
        $fail = fn (string $m) => throw ValidationException::withMessages(['mobile_bar' => $m]);

        if (count($slots) > self::MAX_SLOTS) {
            $fail(sprintf('The bar holds at most %d slots; "More" is always the last one. %d were sent.',
                self::MAX_SLOTS, count($slots)));
        }

        $clean = [];
        $seen = [];

        foreach (array_values($slots) as $i => $slot) {
            $moduleKey = is_array($slot) ? ($slot['module'] ?? null) : null;
            $tabKey = is_array($slot) ? ($slot['tab'] ?? null) : null;

            if (! is_string($moduleKey) || $moduleKey === '' || ($tabKey !== null && ! is_string($tabKey))) {
                $fail(sprintf('Slot %d needs a module, and a tab only as text.', $i + 1));
            }

            $module = $this->registry->get($moduleKey);

            if ($module === null || ! $this->isScreen($module)) {
                $fail(sprintf('"%s" is not a screen that can be on the bar.', $moduleKey));
            }

            if (! $this->availability->isAvailable($moduleKey, $organization)
                || ! in_array("{$moduleKey}.view", $held, true)) {
                $fail(sprintf('This role cannot open %s, so it cannot be on its bar.', $module->label));
            }

            if ($tabKey !== null) {
                $tab = $this->tab($module, $tabKey);

                if ($tab === null) {
                    $fail(sprintf('%s has no tab "%s".', $module->label, $tabKey));
                }

                if (! in_array("{$moduleKey}.{$tab->action()}", $held, true)) {
                    $fail(sprintf('This role cannot open %s › %s, so it cannot be on its bar.',
                        $module->label, $tab->label));
                }
            }

            $pair = $moduleKey.'|'.($tabKey ?? '');

            if (isset($seen[$pair])) {
                $fail(sprintf('%s is on the bar twice.', $this->label($module, $tabKey)));
            }

            $seen[$pair] = true;
            $clean[] = ['module' => $moduleKey, 'tab' => $tabKey];
        }

        return $clean;
    }

    /**
     * What the editor may offer this role.
     *
     * @param  string[]  $held
     * @return array<int, array{module: string, label: string, tabs: array}>
     */
    public function options(array $held, $organization): array
    {
        $out = [];

        foreach ($this->registry->all() as $module) {
            if (! $this->isScreen($module)
                || ! $this->availability->isAvailable($module->key, $organization)
                || ! in_array("{$module->key}.view", $held, true)) {
                continue;
            }

            $tabs = [];
            foreach ($module->tabs as $tab) {
                if (in_array("{$module->key}.{$tab->action()}", $held, true)) {
                    $tabs[] = ['tab' => $tab->key, 'label' => $tab->label];
                }
            }

            $out[] = ['module' => $module->key, 'label' => $module->label, 'tabs' => $tabs];
        }

        return $out;
    }

    /**
     * The person's bar: their role's stored slots, minus what they cannot
     * reach right now. Null when the role never saved one.
     *
     * @return array<int, array{module: string, tab: ?string, label: string}>|null
     */
    public function forUser(User $user): ?array
    {
        $stored = $user->role?->mobile_bar;

        if ($stored === null) {
            return null;
        }

        $out = [];

        foreach ($stored as $slot) {
            $module = $this->registry->get($slot['module'] ?? '');
            $tabKey = $slot['tab'] ?? null;

            if ($module === null || ! $this->isScreen($module) || ! $user->hasPermission("{$module->key}.view")) {
                continue;
            }

            if ($tabKey !== null) {
                $tab = $this->tab($module, $tabKey);

                if ($tab === null || ! $user->hasPermission("{$module->key}.{$tab->action()}")) {
                    continue;
                }
            }

            $out[] = ['module' => $module->key, 'tab' => $tabKey, 'label' => $this->label($module, $tabKey)];
        }

        return $out;
    }

    private function isScreen(ModuleDefinition $module): bool
    {
        return $module->rolesGroup === ModuleDefinition::ROLES_TAB;
    }

    private function tab(ModuleDefinition $module, string $key): ?TabDefinition
    {
        foreach ($module->tabs as $tab) {
            if ($tab->key === $key) {
                return $tab;
            }
        }

        return null;
    }

    private function label(ModuleDefinition $module, ?string $tabKey): string
    {
        return $tabKey === null ? $module->label : ($this->tab($module, $tabKey)?->label ?? $module->label);
    }
}
