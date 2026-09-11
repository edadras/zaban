<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Models\Scene;
use App\Services\Scene\SceneVoiceService;
use Illuminate\Console\Command;

/**
 * Give the acted scenes their voices.
 *
 * Two jobs behind one command because they are the same job from two sides.
 *
 * With `--recording`, a scene is bound to a real course recording: the track is
 * cut at its pauses and one utterance is handed to each line, so the scene is
 * spoken by the voice that actually reads that unit. It binds nothing unless
 * the utterances and the lines come out even, and it reports how well the two
 * agree so a person can decide whether to keep it.
 *
 * Without it, the remaining lines are spoken through the project's voice chain.
 * That is the same chain the rest of the course uses; this command introduces
 * no vendor of its own and no second way of making audio.
 */
class VoiceScenes extends Command
{
    protected $signature = 'scene:voice
        {--scene= : one scene, by slug}
        {--recording= : media asset id of a course recording to cut into lines}
        {--from-disk : adopt line audio already rendered into sources/audio/scenes}
        {--role= : with --recording, bind only this role\'s lines}
        {--dry-run : report what would happen and change nothing}
        {--force : re-render lines that already have audio}
        {--limit=0 : stop after this many generated lines}';

    protected $description = 'Bind scene lines to course recordings, or speak them through the project voice chain';

    public function handle(SceneVoiceService $voices): int
    {
        $scenes = Scene::with('beats')
            ->when($this->option('scene'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($scenes->isEmpty()) {
            $this->error('No scenes matched.');

            return self::FAILURE;
        }

        if ($this->option('from-disk')) {
            return $this->adopt($voices, $scenes);
        }

        if ($id = $this->option('recording')) {
            return $this->bind($voices, $scenes, (int) $id);
        }

        return $this->generate($voices, $scenes);
    }

    private function bind(SceneVoiceService $voices, $scenes, int $recordingId): int
    {
        if ($scenes->count() !== 1) {
            $this->error('Binding a recording needs exactly one scene: pass --scene.');

            return self::FAILURE;
        }

        $recording = MediaAsset::find($recordingId);
        if (! $recording) {
            $this->error("No media asset {$recordingId}.");

            return self::FAILURE;
        }

        $scene = $scenes->first();
        $result = $voices->bindRecording(
            $scene,
            $recording,
            $this->option('role') ?: null,
            ! $this->option('dry-run'),
        );

        $this->line("Scene:     {$scene->slug}");
        $this->line("Recording: {$recording->path}");
        $this->line("Utterances found: {$result['segments']}   lines: {$result['lines']}");

        if (! $result['ok']) {
            $this->warn($result['reason']);

            return self::FAILURE;
        }

        $confidence = $result['confidence'] ?? 0;
        $this->info(
            ($this->option('dry-run') ? 'Would bind ' : 'Bound ')
            .$result['lines'].' lines, agreement '.number_format($confidence, 3),
        );

        if ($confidence < 0.65) {
            $this->warn(
                'Low agreement between line lengths and utterance lengths. '
                .'Check this recording is the conversation the scene was written from.',
            );
        }
        $this->line('Every cut is marked pending: a person has to listen before it is trusted.');

        return self::SUCCESS;
    }

    private function adopt(SceneVoiceService $voices, $scenes): int
    {
        $found = 0;
        $missing = [];

        foreach ($scenes as $scene) {
            foreach ($scene->beats as $beat) {
                $asset = $voices->attachFile($beat);

                if ($asset) {
                    $found++;
                } else {
                    $missing[] = $voices->pathFor($scene->slug, $beat);
                }
            }
        }

        $this->info("Attached {$found} lines from disk.");

        if ($missing) {
            $this->warn(count($missing).' lines have no file yet, starting with '.$missing[0]);
        }

        return self::SUCCESS;
    }

    private function generate(SceneVoiceService $voices, $scenes): int
    {
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        $dry = (bool) $this->option('dry-run');
        $done = 0;
        $failed = 0;

        foreach ($scenes as $scene) {
            foreach ($scene->beats as $beat) {
                if ($limit > 0 && $done >= $limit) {
                    break 2;
                }
                if ($beat->audio_media_asset_id && ! $force) {
                    continue;
                }

                if ($dry) {
                    $this->line("would speak [{$scene->slug} #{$beat->position} {$beat->role}] {$beat->text}");
                    $done++;

                    continue;
                }

                $asset = $voices->speak($beat, $force);

                if ($asset) {
                    $done++;
                    $this->line("spoke [{$scene->slug} #{$beat->position}] -> {$asset->path}");
                } else {
                    $failed++;
                    $this->warn("no voice available for [{$scene->slug} #{$beat->position}]");
                }
            }
        }

        $this->info(($dry ? 'Would speak ' : 'Spoke ')."{$done} lines, {$failed} failed.");

        return $failed > 0 && $done === 0 ? self::FAILURE : self::SUCCESS;
    }
}
