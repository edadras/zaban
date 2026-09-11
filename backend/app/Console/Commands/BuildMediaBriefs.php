<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use App\Services\Media\MediaBriefBuilder;
use Illuminate\Console\Command;

class BuildMediaBriefs extends Command
{
    protected $signature = 'media:briefs
        {--kind= : lesson_scene, vocabulary_card, character_portrait, dialogue_video or lesson_video}
        {--show : print the manifest summary and exit without building}
        {--rerender : also requeue briefs already rendered whose prompt has since changed (this is charged again)}';

    protected $description = 'Plan every image the course needs, before any of it is paid for';

    public function handle(MediaBriefBuilder $builder): int
    {
        if ($this->option('show')) {
            $this->summary();

            return self::SUCCESS;
        }

        $kind = $this->option('kind');

        $builder->rerenderPaidWork((bool) $this->option('rerender'));

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

        if (($locked = $builder->lockedCount()) > 0) {
            $this->newLine();
            $this->line("{$locked} brief(s) are deliberately skipped and were left alone.");
            $this->line('media:skip --unskip puts one back in the queue.');
        }

        if (($stale = $builder->staleCount()) > 0) {
            $this->newLine();
            $this->warn("{$stale} brief(s) have already been rendered from an older prompt and were left alone.");
            $this->line('Their artwork still serves the content. Re-run with --rerender to render them again,');
            $this->line('which is charged in full - so it is worth being sure the new prompt is better first.');
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

        $done = MediaBrief::where('status', MediaBrief::STATUS_IMPORTED)->count();

        if ($done > 0) {
            $this->line("{$done} already rendered and attached to the content.");
        }

        if ($renderable > 0) {
            $this->line('Run media:manifest to export the next batch. Rendering is charged per image - '
                .'see docs/MEDIA_BUDGET.md for what the remainder costs.');
        }
    }
}
