<?php

namespace App\Support\Phone;

use RuntimeException;

/** Why PhoneTexts did not send, with the HTTP status the screen answers with. */
final class TextRefused extends RuntimeException {}
