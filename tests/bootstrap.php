<?php

/*
 * vendor/ may be a junction to another checkout. Composer's ClassLoader root
 * and optimized classmap then follow the real vendor path, so App\ resolves to
 * that other tree. Pin this worktree for both PSR-4 and classmap hits.
 */
$root = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $root;
$_SERVER['APP_BASE_PATH'] = $root;

$loader = require $root.'/vendor/autoload.php';
$loader->addPsr4('App\\', $root.'/app', prepend: true);
$loader->addPsr4('Database\\Factories\\', $root.'/database/factories', prepend: true);
$loader->addPsr4('Database\\Seeders\\', $root.'/database/seeders', prepend: true);

$vendorReal = realpath($root.'/vendor');
$foreignRoot = $vendorReal !== false ? dirname($vendorReal) : null;

if ($foreignRoot !== null && realpath($foreignRoot) !== realpath($root)) {
    $foreignNorm = str_replace('\\', '/', $foreignRoot);
    $rewritten = [];
    foreach ($loader->getClassMap() as $class => $path) {
        $real = realpath($path);
        if ($real === false) {
            continue;
        }
        $realNorm = str_replace('\\', '/', $real);
        if (! str_starts_with($realNorm, $foreignNorm.'/')) {
            continue;
        }
        $relative = substr($realNorm, strlen($foreignNorm));
        if (! str_starts_with($relative, '/app/')
            && ! str_starts_with($relative, '/database/')
            && ! str_starts_with($relative, '/tests/')) {
            continue;
        }
        $candidate = $root.str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($candidate)) {
            $rewritten[$class] = $candidate;
        }
    }
    if ($rewritten !== []) {
        $loader->addClassMap($rewritten);
    }
}
