<?php

namespace App\Support\Phone;

use RuntimeException;

/** A rule's text refused because the number already had its automatic texts today (leads.automatic_texts_per_day): a skip, never a failure. */
final class SmsCapped extends RuntimeException {}
