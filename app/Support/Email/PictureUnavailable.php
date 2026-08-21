<?php

namespace App\Support\Email;

use RuntimeException;

/**
 * One picture in a message could not be loaded.
 *
 * Distinct from {@see CannotReadMailbox}: a missing logo is not a dead inbox.
 * Nothing here writes `status` or `last_reached_at`.
 */
final class PictureUnavailable extends RuntimeException {}
