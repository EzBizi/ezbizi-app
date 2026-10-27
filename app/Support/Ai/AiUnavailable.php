<?php

namespace App\Support\Ai;

use RuntimeException;

/**
 * No AI is configured for this install. A RuntimeException, so every caller
 * that already copes with an AI failure copes with its absence the same way.
 */
final class AiUnavailable extends RuntimeException {}
