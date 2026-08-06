<?php

namespace App\Http\Controllers\Api;

/**
 * Base for API controllers (plan §11.4).
 *
 * Kept separate from any web controller base: the public API is a published
 * product surface with its own versioning and compatibility obligations, and
 * staff control-plane operations deliberately do not live on it.
 */
abstract class Controller
{
    //
}
