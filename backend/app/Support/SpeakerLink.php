<?php

namespace App\Support;

use App\Models\LessonBlock;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\URL;

/**
 * Links to the talking figure.
 *
 * The page it opens is reached by a signed link and holds no credential, the
 * same arrangement the acted-scene player uses. It is worth saying why, because
 * it looks like extra work: the figure is shown inside a web view in the app
 * and inside an iframe in the panel, and neither can be handed a bearer token
 * without either injecting it into a page or leaving it in a URL bar.
 *
 * What a link authorises is therefore small and stated: one figure, one
 * recording, for a few hours. It is not a way into anything else.
 */
class SpeakerLink
{
    /**
     * A link to one figure saying one thing.
     *
     * `$fromMs`/`$toMs` are the window inside the recording. The course's audio
     * is one track per exercise, so a line is a second and a half somewhere
     * inside forty seconds; a speaker handed the whole file says its line and
     * then stands there in silence while the learner waits.
     *
     * @param  string|null  $character  a slug from `speaker.cast`; the presenter by default
     * @param  MediaAsset|null  $audio  what they say, if there is a recording
     * @param  string|null  $caption  the line, shown under them
     * @return array{url: string, expires_in: int}
     */
    public static function for(
        ?string $character = null,
        ?MediaAsset $audio = null,
        ?string $caption = null,
        bool $autoplay = false,
        ?int $fromMs = null,
        ?int $toMs = null,
    ): array {
        $minutes = max(1, (int) config('speaker.link_minutes', 180));

        return [
            'url' => URL::temporarySignedRoute('speaker.show', now()->addMinutes($minutes), array_filter([
                'character' => self::character($character),
                'audio' => $audio?->id,
                // Trimmed: a caption is a line to read, and a signed URL is not
                // the place to carry a paragraph.
                'caption' => $caption === null ? null : mb_substr(trim($caption), 0, 300),
                'autoplay' => $autoplay ? 1 : null,
                'from' => $fromMs !== null ? max(0, $fromMs) : null,
                'to' => $toMs !== null ? max(0, $toMs) : null,
            ], static fn ($value) => $value !== null && $value !== '')),
            'expires_in' => $minutes * 60,
        ];
    }

    /** The presenter who reads lines in lessons. */
    public static function presenter(
        ?MediaAsset $audio = null,
        ?string $caption = null,
        ?int $fromMs = null,
        ?int $toMs = null,
    ): array {
        return self::for(config('speaker.presenter'), $audio, $caption, false, $fromMs, $toMs);
    }

    /**
     * The figure that fronts one lesson block, or nothing.
     *
     * Only the block types where a face is the point: a drill that asks the
     * learner to repeat a line, and a listening item whose whole difficulty is
     * hearing it said. Everywhere else a figure is decoration, and decoration
     * that downloads a 3D renderer is not free.
     *
     * A block with no recording gets nothing at all. A silent presenter would
     * be a face with no reason to be there, and the block already works
     * without one.
     *
     * @return array{url: string, expires_in: int}|null
     */
    public static function forBlock(?LessonBlock $block): ?array
    {
        if (! $block || ! in_array($block->type, ['repeat_after_speaker', 'listen_and_choose'], true)) {
            return null;
        }

        $config = (array) ($block->config ?? []);
        $audio = MediaAsset::find($config['audio_media_asset_id'] ?? null);

        if (! $audio) {
            return null;
        }

        // The first target is the line the learner is about to say, which is
        // the one worth putting under the speaker's chin.
        $targets = array_values(array_filter((array) ($config['targets'] ?? []), 'is_string'));

        return self::presenter(
            $audio,
            $targets[0] ?? null,
            isset($config['audio_start_ms']) ? (int) $config['audio_start_ms'] : null,
            isset($config['audio_end_ms']) ? (int) $config['audio_end_ms'] : null,
        );
    }

    /** The examiner who runs the speaking test. */
    public static function examiner(?MediaAsset $audio = null, ?string $caption = null): array
    {
        return self::for(config('speaker.examiner'), $audio, $caption);
    }

    /**
     * A slug that is actually in the kit.
     *
     * An unknown name falls back to the presenter rather than rendering an
     * empty stage: "the figure did not appear" is not a fault anybody would
     * think to report, and a wrong face is obvious enough to be fixed.
     */
    public static function character(?string $slug): string
    {
        $cast = (array) config('speaker.cast', []);
        $slug = $slug === null ? null : mb_strtolower(trim($slug));

        if ($slug !== null && in_array($slug, $cast, true)) {
            return $slug;
        }

        $presenter = (string) config('speaker.presenter', 'grace');

        return in_array($presenter, $cast, true) ? $presenter : ($cast[0] ?? 'learner');
    }

    /** Whether the modelled kit is actually on disk to be served. */
    public static function kitAvailable(): bool
    {
        $path = (string) config('scene.kit.cast', '');

        return $path !== '' && file_exists(public_path(ltrim($path, '/')));
    }
}
