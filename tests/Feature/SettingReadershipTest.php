<?php

namespace Tests\Feature;

use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingReaders;
use App\Support\Settings\SettingRegistry;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A setting must do something, or say that it doesn't.
 *
 * The owner switched the clock to 24 hours and nothing happened. It saved, it
 * audited, and no code read it. Twenty-three of the thirty settings were in that
 * state, security ones included — an administrator raising the password minimum
 * to 16 for a client's questionnaire got a stored number and twelve-character
 * passwords, which is worse than the setting not existing, because the screen
 * said it was enforced.
 *
 * A code review catches that once. This catches it at setting thirty-one, when
 * whoever adds it is in a hurry and nobody is looking — which is the same job
 * ModuleRegistryTest does for §5.5's automation rule, and the same shape: the
 * check lives beside the thing it checks, and the test is a thin loud assertion
 * on top of it.
 *
 * ── Why this is not a list of exceptions ─────────────────────────
 *
 * Nothing here is maintained by hand. {@see SettingReaders} works out who reads
 * what by reading the code, so a reader that gets deleted stops counting the day
 * it goes, and a setting that gets wired stops being allowed to claim otherwise.
 * An allow-list would have been quicker to write and would have started rotting
 * the same afternoon.
 */
class SettingReadershipTest extends TestCase
{
    /** Scratch trees built by the fixture tests, removed afterwards. */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $directory) {
            $this->deleteTree($directory);
        }

        parent::tearDown();
    }

    /**
     * The one that fails when somebody adds a setting and wires nothing to it.
     */
    public function test_every_setting_either_has_a_reader_or_says_it_has_none(): void
    {
        $registry  = app(SettingRegistry::class);
        $offenders = (new SettingReaders)->silentlyUnread($registry);

        $this->assertSame(
            [],
            array_keys($offenders),
            $this->explain($offenders)
        );
    }

    /**
     * The same lie pointing the other way.
     *
     * Likelier than the first over time: somebody wires a setting, ships it, and
     * leaves the note saying it does nothing sitting on the screen — so an
     * administrator ignores a control that now works.
     */
    public function test_no_setting_still_says_not_yet_once_something_reads_it(): void
    {
        $registry = app(SettingRegistry::class);
        $stale    = (new SettingReaders)->pendingButRead($registry);

        $messages = [];

        foreach ($stale as $key => $files) {
            $messages[] = "  {$key} — now read by ".implode(', ', $files)
                .", but still says: \"{$registry->getOrFail($key)->notYetInEffect}\"";
        }

        $this->assertSame([], array_keys($stale), $messages === [] ? '' : implode("\n", array_merge(
            ['These settings work now and the screen still tells administrators they do not.',
                'Delete the notYetInEffect line in SettingRegistry for each:', ''],
            $messages,
        )));
    }

    /**
     * Every stated reason must be readable by the person it is shown to.
     *
     * Mirrors ModuleRegistryTest's exemption check: an unexplained exemption is
     * indistinguishable from an oversight, and here it is worse, because this
     * text is printed on a screen an administrator is trying to make a decision
     * from.
     */
    public function test_every_pending_setting_states_a_reason_a_tenant_can_read(): void
    {
        foreach (app(SettingRegistry::class)->notYetInEffect() as $key => $definition) {
            $reason = (string) $definition->notYetInEffect;

            $this->assertMatchesRegularExpression(
                '/^[A-Z].*[.!]$/s',
                $reason,
                "Setting '{$key}' gives a reason that is not a sentence: \"{$reason}\". It is "
                .'shown to an administrator, so it reads like prose or it reads like a leak.'
            );
        }

        $this->assertNotEmpty(
            app(SettingRegistry::class)->inEffect(),
            'Every setting claims to be pending, which means the flag has become the default '
            .'answer rather than the honest one.'
        );
    }

    public function test_a_flag_is_refused_where_a_reason_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no reason an administrator could read/');

        // Without strict_types this arrives as the string "1" and would be
        // printed on the settings screen exactly as written here.
        new SettingDefinition(
            key: 'x.y', label: 'X', type: SettingDefinition::TYPE_BOOL, default: false,
            notYetInEffect: true,
        );
    }

    // ── the scanner's own behaviour ──────────────────────────────

    /**
     * The property the whole check rests on.
     *
     * The file that writes at length about a setting is usually the one that has
     * decided not to act on it — PasswordPolicy.php was carrying exactly that
     * paragraph about `security.password_min_length` days before this was
     * written. Any check built on text search reads such a paragraph as proof of
     * the opposite of what it says.
     */
    public function test_a_docblock_naming_a_setting_is_not_a_reader(): void
    {
        $tree = $this->tree([
            'app/Support/Prose.php' => <<<'PHP'
                <?php
                use App\Support\Settings\SettingsResolver;
                /**
                 * What 'demo.mentioned_only' currently does: nothing at all.
                 */
                final class Prose { public const FLOOR = 12; }
                PHP,
        ]);

        $this->assertFalse(
            (new SettingReaders($tree))->hasReader('demo.mentioned_only'),
            'A comment describing a setting was counted as code acting on it — which is the '
            .'exact defect this mechanism exists to catch, now inside the mechanism.'
        );
    }

    public function test_a_read_through_the_resolver_is_a_reader(): void
    {
        $tree = $this->tree([
            'app/Support/Real.php' => <<<'PHP'
                <?php
                use App\Support\Settings\SettingsResolver;
                final class Real {
                    public function go($scope) {
                        return app(SettingsResolver::class)->get('demo.read', $scope);
                    }
                }
                PHP,
        ]);

        $this->assertSame(['app/Support/Real.php'], (new SettingReaders($tree))->for('demo.read'));
    }

    /**
     * A column that happens to share a setting's name is not a reader of it.
     *
     * `timezone` is both, and the model listing it in `$fillable` says nothing
     * about the setting.
     */
    public function test_a_model_naming_a_column_is_not_a_reader(): void
    {
        $tree = $this->tree([
            'app/Models/Thing.php' => <<<'PHP'
                <?php
                class Thing { protected $fillable = ['timezone', 'demo.column_only']; }
                PHP,
        ]);

        $this->assertFalse((new SettingReaders($tree))->hasReader('demo.column_only'));
    }

    public function test_the_client_reading_a_key_off_the_api_row_is_a_reader(): void
    {
        $tree = $this->tree([
            'client/lib/screens/thing_screen.dart' => <<<'DART'
                // A comment naming 'demo.commented' proves nothing.
                Widget build(BuildContext context) {
                  final row = settings.firstWhere((s) => s['key'] == 'demo.client_read');
                  return Text('${row['value']}');
                }
                DART,
        ]);

        $readers = new SettingReaders($tree);

        $this->assertTrue($readers->hasReader('demo.client_read'));
        $this->assertFalse($readers->hasReader('demo.commented'));
    }

    /** The registry names every key, so counting it would make the check pass for everything. */
    public function test_the_registry_is_never_its_own_evidence(): void
    {
        $readers = new SettingReaders;

        foreach (app(SettingRegistry::class)->all() as $key => $_definition) {
            $this->assertNotContains(
                'app/Support/Settings/SettingRegistry.php',
                $readers->for($key),
                "The registry counted as a reader of '{$key}', which would make this check "
                .'pass for every setting forever.'
            );
        }
    }

    // ── helpers ──────────────────────────────────────────────────

    /** @param  array<string,string>  $files  relative path => contents */
    private function tree(array $files): string
    {
        $root = sys_get_temp_dir().'/setting-readers-'.bin2hex(random_bytes(6));
        $this->scratch[] = $root;

        foreach ($files as $relative => $contents) {
            $path = $root.'/'.$relative;
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, $contents);
        }

        return $root;
    }

    private function deleteTree(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    /** @param  array<string, SettingDefinition>  $offenders */
    private function explain(array $offenders): string
    {
        if ($offenders === []) {
            return '';
        }

        $lines = ['', 'These settings are presented as working and no code reads them:', ''];

        foreach ($offenders as $key => $definition) {
            $lines[] = "  {$key} — \"{$definition->label}\"";
        }

        $lines[] = '';
        $lines[] = 'Two honest ways out, and only two.';
        $lines[] = '';
        $lines[] = '  Wire it. Read it through SettingsResolver::get() where the behaviour lives,';
        $lines[] = '  or off the settings row by key in client/lib.';
        $lines[] = '';
        $lines[] = '  Or say so. Add notYetInEffect to the definition in SettingRegistry with a';
        $lines[] = '  sentence for the administrator — "Takes effect when invoicing ships." The';
        $lines[] = '  settings screen prints it, so the control stops claiming to do something.';
        $lines[] = '';
        $lines[] = 'What is not available is leaving it: a saved value that changes nothing is';
        $lines[] = 'worse than a missing setting, because the screen says it is enforced.';

        return implode("\n", $lines);
    }
}
