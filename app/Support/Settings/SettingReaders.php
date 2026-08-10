<?php

namespace App\Support\Settings;

/**
 * Who actually reads a setting (plan §6).
 *
 * ── Why this exists ──────────────────────────────────────────────
 *
 * A definition can claim to be in effect. Only the rest of the codebase can
 * make that true. This class is the thing that checks the claim, so that
 * `notYetInEffect` is a statement the build verifies rather than a second
 * docblock nobody updates. It is what {@see SettingReadershipTest} asserts on,
 * the same way ModuleRegistryTest asserts on ModuleRegistry::violatingAutomationRule().
 *
 * ── Why it is not a grep ─────────────────────────────────────────
 *
 * Because the most detailed writing about a setting is usually in the file that
 * has decided NOT to act on it. While this class was being written,
 * PasswordPolicy.php carried a long docblock about `security.password_min_length`
 * whose whole subject was that nothing read it; a text search scored that file
 * as a reader, which is the opposite of what the paragraph said. It has since
 * been wired and the example no longer stands, which is the point — the file
 * that explains a setting today is the file that ignores it, and a check built
 * on text search cannot tell those apart in either direction.
 *
 * So PHP is read through `token_get_all()`. Comments are tokens, and dropping
 * T_COMMENT and T_DOC_COMMENT removes every prose mention of every key in one
 * step, with no pattern to get wrong. What survives is code.
 * SettingReadershipTest holds a fixture that keeps this honest.
 *
 * ── What counts as a reader ──────────────────────────────────────
 *
 * A file reads a setting when BOTH hold:
 *
 *   1. it is in the settings business at all — PHP files must name
 *      SettingsResolver, which Setting.php already establishes as the only way
 *      to read a value; Dart files must mention settings, since the client
 *      reads them out of the API payload and has no resolver to name;
 *   2. the key appears in it as a string literal, outside comments.
 *
 * Condition 1 is what stops `$fillable = ['timezone', …]` on the Franchise model
 * counting as a reader of the `timezone` setting. Condition 2 is the read
 * itself. Neither alone is enough and both are cheap.
 *
 * Deliberately NOT tightened to "first argument of a get() call". That shape is
 * what today's readers happen to look like, and pinning to it would fail the
 * next reader that resolves a value some other legitimate way — the check would
 * then be enforcing a calling convention while claiming to measure readership.
 * The pair of conditions above measures the thing being asked about.
 *
 * ── What it can still get wrong ──────────────────────────────────
 *
 * Undotted keys are the soft spot. `timezone` is both a setting and a column, so
 * a settings-aware Dart file printing `franchise['timezone']` scores as a reader
 * of the setting. Recorded here rather than hidden: the direction of the error
 * is the bad one — it can call a dead setting live — and a key like `locale` or
 * `time_format` is one column name away from the same mistake. Dotted keys are
 * immune, and new settings should be dotted for that reason among others.
 */
final class SettingReaders
{
    /** Searched for readers. Tests are not readers — a test exercising a setting is not the product using it. */
    private const ROOTS = ['app' => 'php', 'client/lib' => 'dart'];

    /**
     * Files that name every key by construction and so can never be evidence.
     *
     * The registry lists all thirty. This scanner quotes one in the docblock
     * above. Counting either would make the check pass for everything, forever.
     */
    private const NEVER_A_READER = [
        'app/Support/Settings/SettingRegistry.php',
        'app/Support/Settings/SettingDefinition.php',
        'app/Support/Settings/SettingReaders.php',
    ];

    /** @var array<string, string[]>|null literal => relative file paths */
    private ?array $index = null;

    private readonly string $basePath;

    public function __construct(?string $basePath = null)
    {
        $this->basePath = rtrim($basePath ?? base_path(), '/\\');
    }

    /**
     * The files that read a key, relative to the project root.
     *
     * @return string[]
     */
    public function for(string $key): array
    {
        return $this->index()[$key] ?? [];
    }

    public function hasReader(string $key): bool
    {
        return $this->for($key) !== [];
    }

    /**
     * Settings that claim to be in effect and are read by nothing.
     *
     * The failure this whole mechanism exists to prevent: a control the settings
     * screen presents as working, wired to nothing.
     *
     * @return array<string, SettingDefinition>
     */
    public function silentlyUnread(SettingRegistry $registry): array
    {
        return array_filter(
            $registry->all(),
            fn (SettingDefinition $d) => $d->isInEffect() && ! $this->hasReader($d->key)
        );
    }

    /**
     * Settings that say "not yet" while something already reads them.
     *
     * The same lie pointing the other way, and the likelier one over time: a
     * setting gets wired and the note explaining that it does nothing stays put,
     * telling an administrator to expect nothing from a control that now works.
     *
     * @return array<string, string[]> key => the files that read it
     */
    public function pendingButRead(SettingRegistry $registry): array
    {
        $found = [];

        foreach ($registry->notYetInEffect() as $key => $_definition) {
            if ($this->hasReader($key)) {
                $found[$key] = $this->for($key);
            }
        }

        return $found;
    }

    // ── the scan ─────────────────────────────────────────────────

    /** @return array<string, string[]> */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach (self::ROOTS as $root => $language) {
            foreach ($this->sourceFiles($root, $language) as $relative => $source) {
                $literals = $language === 'php'
                    ? $this->phpLiterals($source)
                    : $this->dartLiterals($source);

                foreach (array_unique($literals) as $literal) {
                    $index[$literal][] = $relative;
                }
            }
        }

        return $this->index = $index;
    }

    /**
     * @return iterable<string, string> relative path => contents
     */
    private function sourceFiles(string $root, string $language): iterable
    {
        $directory = $this->basePath.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $root);

        if (! is_dir($directory)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== $language) {
                continue;
            }

            $relative = str_replace(
                DIRECTORY_SEPARATOR, '/',
                substr($file->getPathname(), strlen($this->basePath) + 1)
            );

            if (in_array($relative, self::NEVER_A_READER, strict: true)) {
                continue;
            }

            yield $relative => (string) file_get_contents($file->getPathname());
        }
    }

    /**
     * Every string literal in a PHP file that is in the settings business.
     *
     * Tokenising rather than matching text is the whole point — see the class
     * docblock. T_CONSTANT_ENCAPSED_STRING is a quoted string with no
     * interpolation in it, which is what a setting key is written as.
     *
     * @return string[]
     */
    private function phpLiterals(string $source): array
    {
        $tokens        = @token_get_all($source);
        $literals      = [];
        $namesResolver = false;

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;   // prose, not behaviour
            }

            if (str_contains($text, 'SettingsResolver')) {
                $namesResolver = true;
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $literals[] = substr($text, 1, -1);
            }
        }

        return $namesResolver ? $literals : [];
    }

    /**
     * Every string literal in a Dart file that is in the settings business.
     *
     * No tokeniser to borrow here, so comments come off by hand. The stripper
     * has to know about strings to do it: `'https://…'` is not the start of a
     * comment, and a file where one apostrophe throws the parser out of step
     * would silently stop reporting readers.
     *
     * @return string[]
     */
    private function dartLiterals(string $source): array
    {
        $code = $this->stripDartComments($source);

        if (stripos($code, 'setting') === false) {
            return [];
        }

        preg_match_all('/([\'"])((?:\\\\.|(?!\1).)*)\1/s', $code, $matches);

        return $matches[2] ?? [];
    }

    /** Comments out, string literals intact. Dart block comments nest, so they are counted. */
    private function stripDartComments(string $source): string
    {
        $out    = '';
        $length = strlen($source);
        $i      = 0;

        while ($i < $length) {
            $two = substr($source, $i, 2);

            if ($two === '//') {
                while ($i < $length && $source[$i] !== "\n") {
                    $i++;
                }

                continue;
            }

            if ($two === '/*') {
                $depth = 1;
                $i += 2;

                while ($i < $length && $depth > 0) {
                    $here = substr($source, $i, 2);

                    if ($here === '/*') {
                        $depth++;
                        $i += 2;
                    } elseif ($here === '*/') {
                        $depth--;
                        $i += 2;
                    } else {
                        $i++;
                    }
                }

                continue;
            }

            $character = $source[$i];
            $three     = substr($source, $i, 3);

            if ($three === "'''" || $three === '"""') {
                // A triple-quoted string can hold a lone quote without ending,
                // so the single-quote branch below would lose its place inside
                // one and treat the code after it as string — quietly reporting
                // no readers at all in that file.
                $close = strpos($source, $three, $i + 3);
                $end   = $close === false ? $length : $close + 3;
                $out .= substr($source, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($character === '\'' || $character === '"') {
                // Copied through whole. A key lives inside one of these.
                $quote = $character;
                $out .= $character;
                $i++;

                while ($i < $length) {
                    if ($source[$i] === '\\') {
                        $out .= substr($source, $i, 2);
                        $i += 2;

                        continue;
                    }

                    $out .= $source[$i];

                    if ($source[$i] === $quote || $source[$i] === "\n") {
                        $i++;
                        break;      // newline ends an unterminated literal rather than eating the file
                    }

                    $i++;
                }

                continue;
            }

            $out .= $character;
            $i++;
        }

        return $out;
    }
}
