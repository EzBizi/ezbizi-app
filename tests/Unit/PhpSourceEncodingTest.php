<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class PhpSourceEncodingTest extends TestCase
{
    public function test_php_sources_are_valid_utf8(): void
    {
        $root = dirname(__DIR__, 2);
        $invalid = [];
        foreach (['app', 'config', 'database', 'routes', 'tests', 'scripts', 'resources/views'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php'
                    && preg_match('//u', file_get_contents($file->getPathname())) !== 1) {
                    $invalid[] = $file->getPathname();
                }
            }
        }

        $this->assertSame([], $invalid, 'PHP source text must remain UTF-8 for database and JSON output.');
    }
}
