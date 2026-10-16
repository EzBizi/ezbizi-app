<?php

namespace App\Support\Web\Hours;

use RuntimeException;

/**
 * The destination answered, and the answer was no (a permission, a listing
 * Google will not let this account edit, a value it rejects). Nothing was
 * written, so the place is Blocked, not Unconfirmed.
 */
final class HoursRefused extends RuntimeException {}
