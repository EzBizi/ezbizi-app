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
}
