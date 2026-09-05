<?php

namespace App\Jobs\Media;

use App\Models\Lesson;
use App\Services\Media\MediaGenerationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Media generation is slow and costs money, so it never runs in a request.
 * It also must not retry blindly - a failed generation that is retried three
 * times has been paid for three times.
 */
class GenerateLessonMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 900;

    public function __construct(public int $lessonId, public bool $force = false)
    {
        // The dedicated connection gives this job a retry window longer than its
        // own timeout; on the default connection a slow render would be released
        // mid-flight and paid for twice.
        $this->onConnection(config('queue.default') === 'redis' ? 'redis-long' : config('queue.default'));
        $this->onQueue('media');
    }

    /** Never generate the same lesson's artwork twice concurrently. */
    public function uniqueId(): string
    {
        return 'lesson-media-'.$this->lessonId;
    }

    public function handle(MediaGenerationService $media): void
    {
        $lesson = Lesson::find($this->lessonId);
        if (! $lesson) {
            return;
        }

        $result = $media->ensureLessonScene($lesson, $this->force);

        if ($result['status'] === 'failed') {
            // Log and stop rather than throwing: a provider outage should not
            // spend the retry budget on a call that will fail identically.
            Log::warning('media.lesson_scene.failed', [
                'lesson_id' => $this->lessonId,
                'reason' => $result['reason'] ?? null,
            ]);
        }
    }
}
