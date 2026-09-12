<?php

namespace App\Console\Commands;

use App\Models\MediaAsset;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * A word on a flashcard should be the thing the card says out loud.
 *
 * Every card in a lesson was given the lesson's own recording - the book
 * reading the whole unit, thirty to ninety seconds of it. Tapping play on
 * "cram" played all of it. That recording is the right thing on the listening
 * step, where it already is, and the wrong thing on a card.
 *
 * This is the same two-step shape as the contact sheets: `--export` writes the
 * words that still need a voice and claims them, the operator renders them, and
 * `--import` takes the result back. The render is not done from here because
 * the generator is reached through the operator's own tooling, and because a
 * render costs money and should be a deliberate act.
 *
 * One clip per word, not a strip of them. A strip is a quarter of the price and
 * it was tried first: eight words in one render, split on the pauses. The pauses
 * are not reliably there - the model runs "past papers" and "rote-learning"
 * together in one breath - and a split in the wrong place puts the wrong
 * pronunciation on a word, which is worse than the unit recording it replaces.
 */
class VoiceTheWords extends Command
{
    protected $signature = 'media:voice
        {--export : write the words still waiting for a voice}
        {--import= : a json file of {word, url} from the render}
        {--limit=200 : how many words to export}';

    protected $description = 'Give each vocabulary card the sound of its own word';

    /** Where a word's clip lives on the media disk. */
    public const DIRECTORY = 'generated/word_audio';

    public function handle(): int
    {
        if ($this->option('import')) {
            return $this->import((string) $this->option('import'));
        }

        if ($this->option('export')) {
            return $this->export((int) $this->option('limit'));
        }

        $this->line($this->coverage());

        return self::SUCCESS;
    }

    /**
     * Words a learner would not gain anything from hearing on its own.
     *
     * Two groups. The bare function words - "up", "out", "into" - are taught by
     * the phrasal-verb books and belong on a card, but a learner already knows
     * how to say them, and a recording costs the same as one for a word they
     * cannot. The other group is the language the books use to talk about
     * language: a page headed "Language help" leaves "Language" and "help"
     * behind as if they were the lesson.
     *
     * This decides render order only. Nothing here is removed from a card.
     */
    private const NOT_WORTH_A_RECORDING = [
        'up', 'out', 'off', 'down', 'in', 'on', 'at', 'to', 'into', 'over', 'under',
        'away', 'back', 'about', 'round', 'through', 'all', 'one', 'his', 'her',
        'like', 'someone', 'something', 'well', 'use', 'used',
        'language', 'help', 'example', 'examples', 'meaning', 'meanings',
        'definition', 'explanation', 'verb', 'verbs', 'noun', 'nouns',
        'adjective', 'adjectives', 'adverb', 'idiom', 'idioms', 'phrasal verb',
        'phrasal verbs', 'definition of phrasal verb', 'common mistakes',
        'forward', 'reply', 'note', 'notes',
    ];

    /**
     * The words a card shows, commonest first.
     *
     * Ordering by how many cards carry the word is what makes each render
     * count: the first hundred words voiced cover several hundred cards.
     *
     * @return array<int, array{word: string, cards: int}>
     */
    private function waiting(int $limit): array
    {
        $voiced = MediaAsset::query()
            ->where('path', 'like', self::DIRECTORY.'/%')
            ->pluck('path')
            ->map(fn ($p) => basename($p, '.mp3'))
            ->flip();

        $rows = DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.back_kind'))"),
                ['definition', 'translation', 'example'])
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.front')) as word, COUNT(*) as cards")
            ->groupBy('word')
            ->orderByDesc('cards')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $word = trim((string) $row->word);
            if ($word === '' || $voiced->has(self::key($word))) {
                continue;
            }

            if (in_array(mb_strtolower($word), self::NOT_WORTH_A_RECORDING, true)) {
                continue;
            }

            $out[] = ['word' => $word, 'cards' => (int) $row->cards];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function export(int $limit): int
    {
        $waiting = $this->waiting($limit);

        if ($waiting === []) {
            $this->info('Every card already says its own word.');

            return self::SUCCESS;
        }

        $path = storage_path('app/private/'.self::DIRECTORY.'/to-render.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($waiting, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->info(count($waiting).' words to render, covering '
            .array_sum(array_column($waiting, 'cards')).' cards.');
        $this->line($path);

        return self::SUCCESS;
    }

    /**
     * Take the rendered clips back.
     *
     * Each one is trimmed of the silence the model leaves at either end and
     * written as a small mono mp3, because a card is tapped far more often than
     * a lesson is opened and the file travels on a phone connection.
     */
    private function import(string $file): int
    {
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $rendered = json_decode((string) file_get_contents($file), true);
        if (! is_array($rendered)) {
            $this->error('That file is not a list of rendered words.');

            return self::FAILURE;
        }

        $disk = Storage::disk('local');
        $stored = 0;
        $attached = 0;
        $failed = 0;

        foreach ($rendered as $item) {
            $word = trim((string) ($item['word'] ?? ''));
            $url = (string) ($item['url'] ?? '');

            if ($word === '' || $url === '') {
                $failed++;

                continue;
            }

            $mp3 = $this->fetchAndTrim($url);
            if ($mp3 === null) {
                $this->warn("could not render {$word}");
                $failed++;

                continue;
            }

            $path = self::DIRECTORY.'/'.self::key($word).'.mp3';
            $disk->put($path, $mp3);

            $asset = MediaAsset::updateOrCreate(
                ['disk' => 'local', 'path' => $path],
                [
                    'type' => 'audio',
                    'mime' => 'audio/mpeg',
                    'bytes' => strlen($mp3),
                    'duration_ms' => $this->durationMs($disk->path($path)),
                    'origin' => 'generated',
                    'copyright_status' => 'owned',
                    'metadata' => ['word' => $word, 'source' => 'voice_the_words'],
                ],
            );
            $stored++;
            $attached += $this->attach($word, (int) $asset->id);
        }

        $this->info("clips stored: {$stored}, cards now saying their own word: {$attached}, failed: {$failed}");
        $this->line($this->coverage());

        return $failed > 0 && $stored === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Put the clip on every card that shows this word.
     *
     * The unit recording is not thrown away, only moved aside: the listening
     * step still uses it, and a card that loses its clip can fall back.
     */
    private function attach(string $word, int $assetId): int
    {
        $blocks = DB::table('lesson_blocks')
            ->where('type', 'flashcard')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(config, '$.front')) = ?", [$word])
            ->get(['id', 'config']);

        $touched = 0;
        foreach ($blocks as $block) {
            $config = json_decode((string) $block->config, true);
            if (! is_array($config)) {
                continue;
            }

            $unit = $config['unit_audio_media_asset_id']
                ?? $config['audio_media_asset_id']
                ?? null;

            $config['unit_audio_media_asset_id'] = $unit;
            $config['audio_media_asset_id'] = $assetId;

            DB::table('lesson_blocks')->where('id', $block->id)->update([
                'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
            $touched++;
        }

        return $touched;
    }

    /** Download, cut the leading and trailing silence, and encode small. */
    private function fetchAndTrim(string $url): ?string
    {
        $source = tempnam(sys_get_temp_dir(), 'word').'.wav';
        $target = tempnam(sys_get_temp_dir(), 'word').'.mp3';

        try {
            $bytes = @file_get_contents($url);
            if ($bytes === false || $bytes === '') {
                return null;
            }
            file_put_contents($source, $bytes);

            $trim = 'silenceremove=start_periods=1:start_silence=0.05:start_threshold=-45dB:detection=rms';
            $process = new Process([
                'ffmpeg', '-v', 'error', '-y', '-i', $source,
                '-af', "{$trim},areverse,{$trim},areverse",
                '-codec:a', 'libmp3lame', '-b:a', '64k', '-ar', '24000', '-ac', '1',
                $target,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($target) || filesize($target) < 512) {
                return null;
            }

            return (string) file_get_contents($target);
        } finally {
            @unlink($source);
            @unlink($target);
        }
    }

    private function durationMs(string $path): ?int
    {
        $process = new Process(['ffprobe', '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'csv=p=0', $path]);
        $process->run();

        $seconds = (float) trim($process->getOutput());

        return $seconds > 0 ? (int) round($seconds * 1000) : null;
    }

    private function coverage(): string
    {
        $cards = DB::table('lesson_blocks')->where('type', 'flashcard')
            ->whereNotNull(DB::raw("JSON_EXTRACT(config, '$.back_kind')"))->count();
        $spoken = DB::table('lesson_blocks')->where('type', 'flashcard')
            ->whereNotNull(DB::raw("JSON_EXTRACT(config, '$.unit_audio_media_asset_id')"))->count();

        return "cards saying their own word: {$spoken} of {$cards}";
    }

    /** A stable file name for a word, so a re-render lands on the same clip. */
    public static function key(string $word): string
    {
        return substr(hash('sha256', mb_strtolower(trim($word))), 0, 32);
    }
}
