<?php

namespace App\Support\Tasks\Monday;

use RuntimeException;

/** Monday asked for a wait longer than the caller's time budget: stop now, nothing counted as failed. */
final class MondayBudgetSpent extends RuntimeException {}
