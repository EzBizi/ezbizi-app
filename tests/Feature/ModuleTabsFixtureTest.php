<?php

namespace Tests\Feature;

use App\Support\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * The registry's tabs, written where the client's screens can be checked
 * against them (deploy desk, 2026-09-29).
 *
 * Communication, Newsletter and Social Media each drew tabs the registry never
 * declared, so the roles editor could not grant them. The Flutter half,
 * client/test/module_tabs_registry_test.dart, pumps every module screen and
 * compares its tab strip with this file. This half fails when the file is
 * stale; regenerate with:
 *
 *     WRITE_MODULE_TABS=1 php artisan test tests/Feature/ModuleTabsFixtureTest.php
 */
class ModuleTabsFixtureTest extends TestCase
{
    private const FIXTURE = 'client/test/fixtures/module_tabs.json';

    public function test_the_client_fixture_matches_the_registry(): void
    {
        $registry = app(ModuleRegistry::class);

        $modules = [];
        foreach ($registry->all() as $module) {
            $modules[$module->key] = [
                'label' => $module->label,
                'parent' => $module->parent,
                'tabs' => array_map(fn ($tab) => $tab->label, $module->tabs),
            ];

            // A tab drawn inside another (Marketing › Social Media Management › Compose).
            $labels = array_column(array_map(fn ($t) => ['k' => $t->key, 'l' => $t->label], $module->tabs), 'l', 'k');
            $within = [];
            foreach ($module->tabs as $tab) {
                if ($tab->within !== null) {
                    $within[$tab->label] = $labels[$tab->within] ?? $tab->within;
                }
            }
            if ($within !== []) {
                $modules[$module->key]['within'] = $within;
            }

            // What granting each tab takes (roles audit, 2026-11-24): its key
            // and every key it declares it needs. The client pumps each tab with
            // only these, plus the module's view, and expects it drawn.
            if ($module->tabs !== []) {
                $grants = [];
                foreach ($module->tabs as $tab) {
                    // A passive tab (Monitoring's Activity, API activity) opens
                    // without the module's view, so the client is pumped without it.
                    $passive = in_array($tab->action(), $module->passiveActions, true);
                    $grants[$tab->label] = $this->closure($registry, [
                        ...($passive ? [] : ["{$module->key}.view"]),
                        "{$module->key}.{$tab->action()}",
                    ]);
                }
                $modules[$module->key]['grants'] = $grants;
            }
        }
        ksort($modules);

        $json = json_encode([
            'modules' => $modules,
            'permissions' => array_values($registry->allPermissionKeys()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

        $path = base_path(self::FIXTURE);

        if (getenv('WRITE_MODULE_TABS')) {
            file_put_contents($path, $json);
        }

        $this->assertFileExists($path);
        $this->assertSame(
            $json,
            str_replace("\r\n", "\n", (string) file_get_contents($path)),
            self::FIXTURE.' is stale. Regenerate: WRITE_MODULE_TABS=1 php artisan test '.__FILE__,
        );
    }

    /**
     * The keys, plus every key they declare they need, followed through other
     * modules (a requirement's own requirement is still needed), sorted.
     *
     * @param  string[]  $keys
     * @return string[]
     */
    private function closure(ModuleRegistry $registry, array $keys): array
    {
        $byKey = [];
        foreach ($registry->all() as $module) {
            $byKey[$module->key] = $module;
        }

        $seen = [];
        while ($keys !== []) {
            $key = array_shift($keys);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            [$moduleKey, $action] = explode('.', $key, 2) + [1 => ''];
            $module = $byKey[$moduleKey] ?? null;
            if ($module === null) {
                continue;
            }
            // Any grant in a module implies its view (withImpliedView), except a passive one.
            if (! in_array($action, $module->passiveActions, true)) {
                $keys[] = "{$moduleKey}.view";
            }
            foreach ($module->actionRequires[$action] ?? [] as $needed) {
                $keys[] = $needed;
            }
        }

        $out = array_keys($seen);
        sort($out);

        return $out;
    }
}
