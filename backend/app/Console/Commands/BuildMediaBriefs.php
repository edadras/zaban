<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use App\Services\Media\MediaBriefBuilder;
use Illuminate\Console\Command;

class BuildMediaBriefs extends Command
{
    protected $signature = 'media:briefs
        {--kind= : lesson_scene, vocabulary_card, character_portrait, dialogue_video or lesson_video}
        {--show : print the manifest summary and exit without building}';

    protected $description = 'Plan every image the course needs, before any of it is paid for';

    public function handle(MediaBriefBuilder $builder): int
    {
        if ($this->option('show')) {
            $this->summary();

            return self::SUCCESS;
        }

        $kind = $this->option('kind');

        $written = match ($kind) {
            'lesson_scene' => ['lesson_scene' => $builder->buildLessonScenes()],
            'vocabulary_card' => ['vocabulary_card' => $builder->buildVocabularyCards()],
            'character_portrait' => ['character_portrait' => $builder->buildCharacterPortraits()],
            'dialogue_video' => ['dialogue_video' => $builder->buildDialogueVideos()],
            'lesson_video' => ['lesson_video' => $builder->buildLessonVideos()],
            null => $builder->buildAll(),
            default => null,
        };

        if ($written === null) {
            $this->error("Unknown kind '{$kind}'.");

            return self::FAILURE;
        }

        foreach ($written as $k => $n) {
            $this->line(str_pad($k, 22).': '.$n.' brief(s) written or refreshed');
        }

        $this->newLine();
        $this->summary();

        return self::SUCCESS;
    }

    private function summary(): void
    {
        $rows = MediaBrief::selectRaw('kind, status, count(*) as n')
            ->groupBy('kind', 'status')->orderBy('kind')->orderBy('status')->get();

        $this->table(
            ['kind', 'status', 'count'],
            $rows->map(fn ($r) => [$r->kind, $r->status, $r->n])->all(),
        );

        $renderable = MediaBrief::renderable()->count();
        $this->info("{$renderable} generation(s) queued to render.");

        $blocked = MediaBrief::blocked()->count();

        if ($blocked > 0) {
            $this->line("{$blocked} clip(s) are waiting on the still they animate, and will unblock as those import.");
        }

        if ($renderable > 0) {
            $this->line('Nothing has been generated or charged yet - run media:manifest to export the batch.');
        }
    }
}
