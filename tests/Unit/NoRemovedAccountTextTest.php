<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Jose, 2026-11-24: "STOP SHOWING REMOVED ACCOUNT, THIS GOES FOR EVERYWHERE IN THE APP". */
class NoRemovedAccountTextTest extends TestCase
{
    public function test_no_server_or_client_code_writes_removed_account(): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        foreach (['app', 'client/lib', 'database/migrations'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$dir", \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (! preg_match('/\.(php|dart)$/', $f->getFilename())) {
                    continue;
                }
                foreach (file($f->getPathname()) as $n => $line) {
                    // Allowed: the legacy-row reader and the migration that renames old rows.
                    if (str_contains($line, "'Removed account'") && ! str_contains($line, 'in_array') && ! str_contains($f->getFilename(), 'former_staff')
                        && ! str_contains($f->getFilename(), 'version_history')) {
                        $hits[] = basename($f->getPathname()).':'.($n + 1);
                    }
                }
            }
        }
        $this->assertSame([], $hits, 'show "Former staff" or the real name, never "Removed account"');
    }
}
