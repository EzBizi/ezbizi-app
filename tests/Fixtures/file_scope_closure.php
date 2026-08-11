<?php

/*
 * A closure declared at FILE SCOPE, which is the shape routes/api.php:407 and
 * routes/web.php:5 already have.
 *
 * On PHP 8.4 such a closure's frame name is `{closure:<ABSOLUTE FILE>:<line>}` —
 * the deploy root inside the identifier, where ExceptionAudit's path scrubbing
 * did not look. A closure declared inside a method is named after the method
 * instead and cannot reproduce it, which is why this lives in its own file.
 */

return fn () => throw new RuntimeException('boom');
