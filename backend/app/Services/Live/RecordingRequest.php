<?php

namespace App\Services\Live;

/**
 * What to record, how to lay it out, and where the file goes.
 *
 * A value object rather than a bag of arguments because the two output shapes -
 * a file on a shared volume, or an object in a bucket - differ in every field
 * but mean the same thing to the caller.
 */
final class RecordingRequest
{
    public function __construct(
        public readonly string $room,
        /** 'grid', 'speaker' or 'single-speaker'. */
        public readonly string $layout = 'speaker',
        /** 'file' or 's3'. */
        public readonly string $output = 'file',
        /** For 'file': the path the media server writes to, as it sees it. */
        public readonly string $filepath = '',
        /** For 's3': bucket, region, endpoint, access_key, secret. */
        public readonly array $s3 = [],
        public readonly bool $audioOnly = false,
    ) {}
}
