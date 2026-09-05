<?php

namespace App\Console\Commands;

use App\Jobs\Media\GenerateLessonMedia;
use App\Services\Media\MediaGenerationService;
use Illuminate\Console\Command;

class GenerateMedia extends Command
{
    protected $signature = 'media:generate
        {--limit=25 : how many lessons to queue}
        {--force : regenerate even where artwork already exists}
        {--estimate : report the cost of the backlog and exit}';

    protected $description = 'Queue AI artwork generation for lessons that have none';

    public function handle(MediaGenerationService $media): int
    {
        if ($this->option('estimate')) {
            $e = $media->estimateBacklog();
            foreach ($e as $k => $v) {
                $this->line(str_pad((string) $k, 30).': '.($v ?? '—'));
            }

            return self::SUCCESS;
        }

        $limit = (int) $this->option('limit');
        $lessons = $media->lessonsMissingArtwork($limit);

        if ($lessons->isEmpty()) {
            $this->info('Every lesson with concepts already has artwork.');

            return self::SUCCESS;
        }

        foreach ($lessons as $lesson) {
            GenerateLessonMedia::dispatch($lesson->id, (bool) $this->option('force'));
        }

        $this->info("Queued {$lessons->count()} lesson(s) on the 'media' queue.");
        $this->line('Cost is charged per generation - run with --estimate first if the backlog is large.');

        return self::SUCCESS;
    }
}
