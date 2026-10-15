<?php

namespace App\Support\Social\Publishing;

use RuntimeException;

/**
 * Instagram took the video but had not finished ingesting it inside the time
 * one queue job may run. Not a failure: PublishSocialTarget hands the container
 * to FinishInstagramPost, which keeps checking from delayed jobs.
 *
 * `handle` is the media handoff, still held: Instagram may still be fetching
 * the file, so it is released by whoever finishes, not here.
 */
final class StillProcessing extends RuntimeException
{
    public function __construct(
        public readonly string $container,
        public readonly string $handle,
    ) {
        parent::__construct('Instagram is still processing the video.');
    }
}
