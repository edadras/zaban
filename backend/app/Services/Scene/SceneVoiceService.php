<?php

namespace App\Services\Scene;

use App\AI\AiOrchestrator;
use App\AI\Support\MediaRequest;
use App\Models\Character;
use App\Models\MediaAsset;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Support\MediaPath;
use Illuminate\Support\Facades\Storage;

/**
 * Gives a scene its voices.
 *
 * Two ways in, and the order matters.
 *
 * The first is the course's own recordings. A unit's audio is a real person
 * reading real sentences, and a scene written from that page should be spoken
 * by that recording rather than by anything synthesised: `bindRecording()` cuts
 * the track into utterances and hands one to each line. It refuses when the
 * numbers do not line up, because a scene where every line is one line out is
 * worse than a scene with no audio at all.
 *
 * The second is the project's voice chain - the same chain the rest of the
 * course uses for spoken material, so a scene never introduces a vendor of its
 * own. It is used for lines the recordings do not cover.
 */
class SceneVoiceService
{
    /** Where generated scene audio is kept, next to the rest of the course audio. */
    private const PATH = 'sources/audio/scenes';

    public function __construct(
        private AiOrchestrator $ai,
        private AudioSegmenter $segmenter,
    ) {}

    /**
     * Bind a scene's lines to utterances inside one real recording.
     *
     * @param  string|null  $role  bind only this role's lines, when the recording holds one voice
     * @return array{bound:int,segments:int,lines:int,ok:bool,reason:?string}
     */
    public function bindRecording(Scene $scene, MediaAsset $recording, ?string $role = null, bool $apply = true): array
    {
        $beats = $scene->beats()->get()
            ->when($role !== null, fn ($b) => $b->where('role', $role))
            ->values();

        $file = MediaPath::absolute($recording);

        if (! $file) {
            return $this->outcome(0, 0, $beats->count(), false, 'The recording is not on a local disk.');
        }
        if (! $this->segmenter->isAvailable()) {
            return $this->outcome(0, 0, $beats->count(), false, 'ffmpeg is not installed on this host.');
        }

        $segments = $this->segmenter->segments($file);
        $found = count($segments);

        if ($found === 0) {
            return $this->outcome(0, 0, $beats->count(), false, 'No speech was found in the recording.');
        }

        if ($found > $beats->count()) {
            // A speaker pausing mid-sentence splits one line in two; joining the
            // closest pairs is a repair, and only up to a point.
            $segments = $this->segmenter->fit($segments, $beats->count());
        }

        if (count($segments) !== $beats->count()) {
            return $this->outcome(
                0,
                $found,
                $beats->count(),
                false,
                'The recording holds '.count($segments).' utterances and the scene has '
                    .$beats->count().' lines. Nothing was bound: a scene one line out of step is '
                    .'harder to fix than a scene with no audio.',
            );
        }

        // How far the utterance lengths track the line lengths. A recording of
        // the right conversation rises and falls with the script; an unrelated
        // one does not, and the number says which this is.
        $confidence = $this->agreement($beats->all(), $segments);

        if ($apply) {
            foreach ($beats as $index => $beat) {
                $beat->update([
                    'audio_media_asset_id' => $recording->id,
                    'audio_start_ms' => $segments[$index]['start'],
                    'audio_end_ms' => $segments[$index]['end'],
                    'audio_method' => 'silence_segmentation',
                    'audio_confidence' => $confidence,
                    // Cut by a machine that cannot read: a person still has to agree.
                    'audio_review_status' => 'pending',
                ]);
            }
        }

        return [
            ...$this->outcome($apply ? $beats->count() : 0, $found, $beats->count(), true, null),
            'confidence' => $confidence,
        ];
    }

    /**
     * Speak one line through the project's voice chain and keep the result.
     *
     * Returns the existing asset untouched when the line already has audio,
     * unless it is asked to do the work again - re-rendering a hundred lines
     * every deploy would spend credits to produce identical files.
     */
    public function speak(SceneBeat $beat, bool $force = false): ?MediaAsset
    {
        if (! $force && $beat->audio_media_asset_id) {
            return $beat->audio;
        }

        $scene = $beat->scene;
        $voice = $this->voiceFor($scene, $beat->role);

        $result = $this->ai->audio(new MediaRequest(
            feature: 'scene.line',
            prompt: $beat->text,
            voice: $voice,
            metadata: [
                'scene' => $scene->slug,
                'position' => $beat->position,
                'role' => $beat->role,
            ],
            // The same line in the same voice is the same file, every time.
            cacheable: true,
        ));

        if (! $result->ok) {
            return null;
        }

        $asset = $this->store($result->localPath, $result->url, $result->mime, $scene, $beat, $result->model);

        if ($asset) {
            $beat->update([
                'audio_media_asset_id' => $asset->id,
                'audio_start_ms' => null,
                'audio_end_ms' => null,
                'audio_method' => 'generated',
                'audio_confidence' => 1.0,
                'audio_review_status' => 'pending',
            ]);
        }

        return $asset;
    }

    /**
     * Adopt a line's audio that is already on disk.
     *
     * The path is the convention this service writes to and the one a render
     * run outside the application writes to as well, which is how a batch of
     * lines voiced on the studio account becomes course media without a second
     * import format existing.
     */
    public function attachFile(SceneBeat $beat, ?string $relative = null): ?MediaAsset
    {
        $scene = $beat->scene;
        $relative ??= $this->pathFor($scene->slug, $beat);

        $absolute = base_path('../'.$relative);
        if (! is_file($absolute)) {
            return null;
        }

        $bytes = filesize($absolute) ?: null;

        $asset = MediaAsset::updateOrCreate(
            ['disk' => 'local', 'path' => $relative],
            [
                'type' => 'audio',
                'mime' => str_ends_with($relative, '.wav') ? 'audio/wav' : 'audio/mpeg',
                'bytes' => $bytes,
                'checksum' => hash_file('sha256', $absolute),
                'origin' => 'generated',
                'copyright_status' => 'owned',
                'metadata' => [
                    'scene' => $scene->slug,
                    'position' => $beat->position,
                    'role' => $beat->role,
                    'text' => $beat->text,
                ],
            ],
        );

        $beat->update([
            'audio_media_asset_id' => $asset->id,
            'audio_start_ms' => null,
            'audio_end_ms' => null,
            'audio_method' => 'generated',
            'audio_confidence' => 1.0,
            'audio_review_status' => $beat->audio_review_status === 'approved' ? 'approved' : 'pending',
        ]);

        return $asset;
    }

    /** Where a scene's line lives, whoever rendered it. */
    public function pathFor(string $slug, SceneBeat $beat, string $extension = 'mp3'): string
    {
        return sprintf('%s/%s/%02d-%s.%s', self::PATH, $slug, $beat->position, $beat->role, $extension);
    }

    /** The voice a role speaks in, from the cast member behind it. */
    public function voiceFor(Scene $scene, string $role): ?string
    {
        $member = $scene->castFor($role);
        $slug = $member['character'] ?? null;

        if (! $slug) {
            return $member['voice'] ?? null;
        }

        $character = Character::where('slug', $slug)->first();

        return $character?->voice_id ?: $character?->accent;
    }

    // ------------------------------------------------------------- internals

    /**
     * Register a rendered file as course media.
     *
     * @param  string|null  $localPath  path on the AI storage disk
     */
    private function store(
        ?string $localPath,
        ?string $url,
        ?string $mime,
        Scene $scene,
        SceneBeat $beat,
        ?string $model,
    ): ?MediaAsset {
        $source = config('ai.storage_disk', 'local');
        $bytes = null;

        if ($localPath && Storage::disk($source)->exists($localPath)) {
            $bytes = Storage::disk($source)->get($localPath);
        } elseif ($url) {
            $bytes = @file_get_contents($url);
        }

        if ($bytes === null || $bytes === false || $bytes === '') {
            return null;
        }

        $extension = match ($mime) {
            'audio/mpeg' => 'mp3',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            default => 'mp3',
        };

        $path = self::PATH."/{$scene->slug}/{$beat->position}-{$beat->role}.{$extension}";
        Storage::disk('local')->put($path, $bytes);

        return MediaAsset::updateOrCreate(
            ['disk' => 'local', 'path' => $path],
            [
                'type' => 'audio',
                'mime' => $mime ?: 'audio/mpeg',
                'bytes' => strlen($bytes),
                'checksum' => hash('sha256', $bytes),
                'origin' => 'generated',
                'copyright_status' => 'owned',
                'metadata' => [
                    'scene' => $scene->slug,
                    'position' => $beat->position,
                    'role' => $beat->role,
                    'model' => $model,
                    'text' => $beat->text,
                ],
            ],
        );
    }

    /**
     * How well the utterance lengths agree with the line lengths, 0 to 1.
     *
     * Pearson correlation over the pairs, squashed into a confidence: strong
     * agreement means the recording is plausibly this conversation, and a flat
     * or inverted relationship means it probably is not.
     */
    private function agreement(array $beats, array $segments): float
    {
        $x = array_map(fn (SceneBeat $b) => (float) mb_strlen($b->text), $beats);
        $y = array_map(fn ($s) => (float) $s['duration'], $segments);
        $n = count($x);

        if ($n < 3) {
            return 0.5;
        }

        $meanX = array_sum($x) / $n;
        $meanY = array_sum($y) / $n;
        $cov = 0.0;
        $varX = 0.0;
        $varY = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dx = $x[$i] - $meanX;
            $dy = $y[$i] - $meanY;
            $cov += $dx * $dy;
            $varX += $dx * $dx;
            $varY += $dy * $dy;
        }

        if ($varX <= 0.0 || $varY <= 0.0) {
            return 0.5;
        }

        $r = $cov / sqrt($varX * $varY);

        return round(max(0.0, min(1.0, ($r + 1) / 2)), 3);
    }

    private function outcome(int $bound, int $segments, int $lines, bool $ok, ?string $reason): array
    {
        return [
            'bound' => $bound,
            'segments' => $segments,
            'lines' => $lines,
            'ok' => $ok,
            'reason' => $reason,
        ];
    }
}
