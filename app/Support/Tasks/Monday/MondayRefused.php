<?php

namespace App\Support\Tasks\Monday;

use RuntimeException;

/** Monday refused the token itself: the connection needs connecting again. */
final class MondayRefused extends RuntimeException {}
