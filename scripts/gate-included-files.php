<?php

// Prepended (php -d auto_prepend_file=...) to the re-run of a failed gate's tests
// by scripts/gate-backend.sh. When the process ends it appends every PHP file it
// loaded to $EZBIZI_INCLUDED_FILES: the code those tests exercise, which decides
// whether the next cut may re-run only them (deploy.sh, "re-run only what failed").
// Nothing is written when the variable is unset, so a stray prepend is inert.
register_shutdown_function(static function (): void {
    $out = getenv('EZBIZI_INCLUDED_FILES');
    if (is_string($out) && $out !== '') {
        file_put_contents($out, implode(PHP_EOL, get_included_files()).PHP_EOL, FILE_APPEND | LOCK_EX);
    }
});
