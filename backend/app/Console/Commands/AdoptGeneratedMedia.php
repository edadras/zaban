<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use App\Models\VocabularySense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Put the rendered media back on the lessons, on a host that has never seen it.
 *
 * The files travel in the repository; the rows that point at them do not. A
 * fresh deploy runs `content:import` and `content:build-activities`, and
 * neither knows anything about generation - so the pictures and the word clips
 * are sitting on disk while every lesson shows an empty frame and every card
 * plays the whole unit recording. That is what a deploy of this looked like.
 *
 * `media:import` was the only way back and it is the wrong one here: it fetches
 * from the provider's URLs, which expire, and it charges nothing but needs the
 * network and the brief rows. Nothing has to be fetched. The bytes are already
 * on the disk, and all that is missing is which lesson each one belongs to.
 *
 * So that is what the manifest holds, and it is keyed on the content rather
 * than on any row id: a scene by the page and section it illustrates, a card by
 * the headword and the sentence it shows, a portrait by the character's slug, a
 * word clip by the word. Those survive a re-import; primary keys do not.
 */
class AdoptGeneratedMedia extends Command
{
    protected $signature = 'media:adopt
        {--export : write the manifest from this database}
        {--manifest=docs/data/generated-media.json : where the manifest lives}';

    protected $description = 'Reconnect the rendered pictures and word clips to the lessons they belong to';

    public function handle(): int
    {
        $path = base_path('../'.$this->option('manifest'));

        return $this->option('export') ? $this->export($path) : $this->adopt($path);
    }

    /** Write down what each rendered file belongs to. */
    private function export(string $path): int
    {
        $manifest = [
            'scenes' => $this->scenes(),
            'cards' => $this->cards(),
            'portraits' => $this->portraits(),
            'words' => $this->words(),
        ];

        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($manifest as $kind => $rows) {
            $this->line(sprintf('   %-10s %d', $kind, count($rows)));
        }
        $this->info('written: '.$path);

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scenes(): array
    {
        // Every picture on a lesson, not only the rendered ones. The book's own
        // artwork is attached by `content:import` and should therefore already
        // be there - but a manifest that covers half the pictures is a manifest
        // nobody can check, and the cost of carrying the other half is a few
        // hundred lines of JSON.
        return DB::table('lesson_blocks as lb')
            ->join('media_assets as ma', 'ma.id', '=', 'lb.media_asset_id')
            ->join('lessons as l', 'l.id', '=', 'lb.lesson_id')
            ->whereNull('ma.deleted_at')
            ->orderBy('ma.path')
            ->get(['ma.path', 'ma.width', 'ma.height', 'ma.mime', 'ma.origin',
                'l.source_document_id as doc', 'l.source_page as page',
                'l.source_section as section', 'lb.position'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cards(): array
    {
        return DB::table('examples as e')
            ->join('media_assets as ma', 'ma.id', '=', 'e.media_asset_id')
            ->join('vocabulary_senses as vs', function ($j) {
                $j->on('vs.id', '=', 'e.exemplifiable_id')
                    ->where('e.exemplifiable_type', '=', VocabularySense::class);
            })
            ->join('vocabulary_items as vi', 'vi.id', '=', 'vs.vocabulary_item_id')
            ->where('ma.origin', 'generated')
            ->whereNull('ma.deleted_at')
            ->orderBy('ma.path')
            ->get(['ma.path', 'ma.width', 'ma.height', 'ma.mime',
                'vi.headword', 'vs.sense_number', 'e.text'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function portraits(): array
    {
        $rows = [];

        foreach (['avatar_media_asset_id' => 'avatar', 'reference_media_asset_id' => 'reference'] as $column => $role) {
            $rows = array_merge($rows, DB::table('characters as c')
                ->join('media_assets as ma', 'ma.id', '=', 'c.'.$column)
                ->where('ma.origin', 'generated')
                ->whereNull('ma.deleted_at')
                ->get(['ma.path', 'ma.width', 'ma.height', 'ma.mime', 'c.slug'])
                ->map(fn ($r) => (array) $r + ['role' => $role])->all());
        }

        return $rows;
    }

    /**
     * The word clips need no manifest entry beyond the word itself.
     *
     * `media:voice` names each file after the word it says, so the mapping is
     * in the file name. What is written here is the word, so a host can rebuild
     * the rows without having to reverse a hash.
     *
     * @return array<int, array<string, mixed>>
     */
    private function words(): array
    {
        return MediaAsset::query()
            ->where('path', 'like', VoiceTheWords::DIRECTORY.'/%')
            ->whereNull('deleted_at')
            ->orderBy('path')
            ->get(['path', 'duration_ms', 'metadata'])
            ->map(fn (MediaAsset $a) => [
                'path' => $a->path,
                'word' => $a->metadata['word'] ?? null,
                'duration_ms' => $a->duration_ms,
            ])
            ->filter(fn ($r) => $r['word'] !== null)
            ->values()->all();
    }

    /** Replay the manifest against whatever this host's database calls things. */
    private function adopt(string $path): int
    {
        if (! is_file($path)) {
            $this->error("No manifest at {$path}. Run with --export on a host that has the rows.");

            return self::FAILURE;
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest)) {
            $this->error('That manifest will not parse.');

            return self::FAILURE;
        }

        $missing = 0;
        $counts = [];

        $counts['scenes'] = $this->adoptScenes($manifest['scenes'] ?? [], $missing);
        $counts['cards'] = $this->adoptCards($manifest['cards'] ?? [], $missing);
        $counts['portraits'] = $this->adoptPortraits($manifest['portraits'] ?? [], $missing);
        $counts['words'] = $this->adoptWords($manifest['words'] ?? [], $missing);

        foreach ($counts as $kind => $n) {
            $this->line(sprintf('   %-10s attached: %d', $kind, $n));
        }

        if ($missing > 0) {
            $this->warn("{$missing} files named in the manifest are not on this disk. Run `git lfs pull`.");
        }

        return self::SUCCESS;
    }

    /**
     * A row for a file that is already here.
     *
     * Keyed on the path, so running this twice is the same as running it once.
     */
    private function asset(array $row, string $type, int &$missing): ?MediaAsset
    {
        $path = (string) ($row['path'] ?? '');
        if ($path === '' || ! Storage::disk('local')->fileExists($path)) {
            $missing++;

            return null;
        }

        return MediaAsset::updateOrCreate(
            ['disk' => 'local', 'path' => $path],
            [
                'type' => $type,
                'mime' => $row['mime'] ?? ($type === 'audio' ? 'audio/mpeg' : 'image/png'),
                'bytes' => Storage::disk('local')->size($path),
                'width' => $row['width'] ?? null,
                'height' => $row['height'] ?? null,
                'duration_ms' => $row['duration_ms'] ?? null,
                'origin' => $row['origin'] ?? 'generated',
                'copyright_status' => 'owned',
                'metadata' => isset($row['word']) ? ['word' => $row['word'], 'source' => 'voice_the_words'] : null,
            ],
        );
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function adoptScenes(array $rows, int &$missing): int
    {
        $done = 0;

        foreach ($rows as $row) {
            $asset = $this->asset($row, 'image', $missing);
            if (! $asset) {
                continue;
            }

            $lesson = DB::table('lessons')
                ->where('source_document_id', $row['doc'])
                ->where('source_page', $row['page'])
                ->when($row['section'] !== null, fn ($q) => $q->where('source_section', $row['section']))
                ->whereNull('deleted_at')
                ->value('id');

            if (! $lesson) {
                continue;
            }

            $done += DB::table('lesson_blocks')
                ->where('lesson_id', $lesson)
                ->where('type', 'image_scene')
                ->where('position', $row['position'])
                ->update(['media_asset_id' => $asset->id, 'updated_at' => now()]);
        }

        return $done;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function adoptCards(array $rows, int &$missing): int
    {
        $done = 0;

        foreach ($rows as $row) {
            $asset = $this->asset($row, 'image', $missing);
            if (! $asset) {
                continue;
            }

            $sense = DB::table('vocabulary_senses as vs')
                ->join('vocabulary_items as vi', 'vi.id', '=', 'vs.vocabulary_item_id')
                ->where('vi.headword', $row['headword'])
                ->where('vs.sense_number', $row['sense_number'])
                ->value('vs.id');

            if (! $sense) {
                continue;
            }

            $done += DB::table('examples')
                ->where('exemplifiable_type', VocabularySense::class)
                ->where('exemplifiable_id', $sense)
                ->where('text', $row['text'])
                ->update(['media_asset_id' => $asset->id, 'updated_at' => now()]);
        }

        return $done;
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function adoptPortraits(array $rows, int &$missing): int
    {
        $done = 0;

        foreach ($rows as $row) {
            $asset = $this->asset($row, 'image', $missing);
            if (! $asset) {
                continue;
            }

            $column = $row['role'] === 'reference' ? 'reference_media_asset_id' : 'avatar_media_asset_id';

            $done += DB::table('characters')->where('slug', $row['slug'])
                ->update([$column => $asset->id, 'updated_at' => now()]);
        }

        return $done;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function adoptWords(array $rows, int &$missing): int
    {
        $done = 0;

        foreach ($rows as $row) {
            $asset = $this->asset($row, 'audio', $missing);
            if (! $asset) {
                continue;
            }

            $done += DB::table('lesson_blocks')
                ->where('type', 'flashcard')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.front')) = ?", [$row['word']])
                ->update([
                    'config' => DB::raw("JSON_SET(config, '$.audio_media_asset_id', {$asset->id})"),
                    'updated_at' => now(),
                ]);
        }

        return $done;
    }
}
