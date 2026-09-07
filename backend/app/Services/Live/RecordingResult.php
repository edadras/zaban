<?php

namespace App\Services\Live;

/** What the media server made, once it has finished making it. */
final class RecordingResult
{
    public function __construct(
        public readonly string $egressId,
        /** complete | failed | in_progress */
        public readonly string $status,
        /** The name the media server gave the file, as it wrote it. */
        public readonly ?string $filename = null,
        public readonly ?int $durationMs = null,
        public readonly ?int $bytes = null,
        /** For object storage: where it ended up. */
        public readonly ?string $location = null,
        public readonly ?string $error = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->status === 'complete';
    }
}
