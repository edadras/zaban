<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\Translation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Attach a first-language meaning to the words the books teach.
 *
 * The `translations` table was created in the first schema and held nothing for
 * the life of the project. Everything the learner was told about a word was in
 * English: a gloss from the book's own margin, or nothing. That is defensible
 * at C1 and useless at A1, where the word being explained and the explanation
 * are equally unknown.
 *
 * The catalogue is `docs/data/translations/<code>.json`, a flat map from the
 * English headword to one meaning. It is written by hand and reviewed as text,
 * which is the only honest way to do this offline: there is no bilingual
 * dictionary in the repository and inventing one from a model that is not
 * configured would put a wrong meaning in front of a learner.
 *
 * The catalogue is keyed by headword and the table stores senses, which looks
 * like a mismatch and is not one here. A "sense" in this corpus is an
 * occurrence, not a meaning: "brother" has two because it is taught in the
 * elementary vocabulary book and again in the advanced idioms book, and neither
 * row carries a part of speech or a definition that tells them apart. So the
 * meaning is attached to every sense of the headword, which is the only reading
 * the data supports.
 *
 * What that leaves is real polysemy — "book" the object and "book" the verb —
 * and the answer to that is in the catalogue rather than in the schema: an
 * entry may carry more than one meaning, separated by "؛", the way a bilingual
 * dictionary prints them. A learner is better served by two meanings than by
 * the wrong one of them.
 */
class ImportTranslations extends Command
{
    protected $signature = 'content:translate
                            {--language=fa : the language code of the catalogue to import}
                            {--fresh : remove this language\'s translations first}';

    protected $description = 'Import the first-language meanings of taught words';

    public function handle(): int
    {
        $code = (string) $this->option('language');
        $language = Language::where('code', $code)->first();

        if ($language === null) {
            $this->error("No language with code {$code}.");

            return self::FAILURE;
        }

        $path = base_path("../docs/data/translations/{$code}.json");
        if (! is_file($path)) {
            $this->error("No catalogue at {$path}.");

            return self::FAILURE;
        }

        /** @var array<string, string> $catalogue */
        $catalogue = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        if ($this->option('fresh')) {
            $removed = Translation::where('language_id', $language->id)->delete();
            $this->line("   removed: {$removed}");
        }

        // Every sense of every headword. A headword taught in three books has
        // three, and the meaning belongs on all of them.
        $senseRows = DB::table('vocabulary_items')
            ->join('vocabulary_senses', 'vocabulary_senses.vocabulary_item_id', '=', 'vocabulary_items.id')
            ->select('vocabulary_items.headword', 'vocabulary_senses.id')
            ->get();

        $senses = $senseRows
            ->groupBy(fn ($r) => mb_strtolower($r->headword))
            ->map(fn ($rows) => $rows->pluck('id')->all());

        // A second index, consulted only when the exact headword misses. The
        // scanner carries the book's own section markers into the headword -
        // "b| careers advice", "the golden era®" - and the catalogue is written
        // in the English the book teaches rather than in the marks a scanner
        // left on the page. Two headwords sharing a normalised form are the
        // same word wearing different marks, so they are matched together.
        $loose = $senseRows
            ->groupBy(fn ($r) => $this->normalise($r->headword))
            ->reject(fn ($rows, $key) => $key === '')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        $written = 0;
        $words = 0;
        $unknown = [];

        // One meaning per sense, decided before anything is written. A sense
        // can be reached twice - once by its own headword and once by the same
        // word wearing a scanner's label - and writing both would leave the
        // word's meaning decided by whichever entry the loop reached last.
        // The exact headword is the better reading and keeps its claim.
        $chosen = [];

        foreach ($catalogue as $headword => $meaning) {
            $meaning = trim((string) $meaning);
            $key = mb_strtolower(trim((string) $headword));
            $exact = $senses->get($key) ?? [];
            $labelled = $loose->get($this->normalise((string) $headword)) ?? [];

            if ($meaning === '') {
                continue;
            }
            if ($exact === [] && $labelled === []) {
                $unknown[] = $headword;

                continue;
            }

            $words++;
            foreach ([[$labelled, false], [$exact, true]] as [$ids, $isExact]) {
                foreach ($ids as $senseId) {
                    $senseId = (int) $senseId;
                    if (isset($chosen[$senseId]) && $chosen[$senseId]['exact'] && ! $isExact) {
                        continue;
                    }
                    $chosen[$senseId] = ['text' => $meaning, 'exact' => $isExact];
                }
            }
        }

        $rows = [];
        foreach ($chosen as $senseId => $choice) {
            $rows[] = [
                'vocabulary_sense_id' => $senseId,
                'language_id' => $language->id,
                'text' => $choice['text'],
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) >= 500) {
                $written += $this->flush($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            $written += $this->flush($rows);
        }

        $total = Translation::where('language_id', $language->id)->count();
        $taught = DB::table('concepts')
            ->where('conceptable_type', \App\Models\VocabularySense::class)
            ->where('is_active', true)
            ->distinct()->count('label');

        $covered = DB::table('concepts')
            ->join('translations', function ($j) use ($language) {
                $j->on('translations.vocabulary_sense_id', '=', 'concepts.conceptable_id')
                    ->where('translations.language_id', '=', $language->id);
            })
            ->where('concepts.conceptable_type', \App\Models\VocabularySense::class)
            ->where('concepts.is_active', true)
            ->distinct()->count('concepts.label');

        $this->info("Words translated: {$words} ({$written} sense rows, {$total} in total)");
        $this->line("   {$code} now covers {$covered} of {$taught} taught words"
            .' ('.round(100 * $covered / max(1, $taught)).'%)');

        if ($unknown !== []) {
            // Worth seeing: an entry the corpus does not teach is either a
            // typo in the catalogue or effort spent on a word nobody meets.
            $this->warn('   in the catalogue but not in the corpus: '.count($unknown));
            $this->line('     '.implode(', ', array_slice($unknown, 0, 12)));
        }

        return self::SUCCESS;
    }

    /**
     * A headword with the scanner's marks taken off.
     *
     * Only the marks: a leading section label from the page ("b| ", "| ", "6. "),
     * a trailing footnote mark or stray quote, and repeated space. Nothing that
     * could turn one word into another.
     */
    private function normalise(string $headword): string
    {
        $h = mb_strtolower(trim($headword));
        // A section label from the page: "b| ", a bare "| ", or "6. ".
        $h = preg_replace('/^(?:[a-e]?\||\d+\.)\s*/u', '', $h) ?? $h;
        $h = preg_replace('/[®*\x{2019}\x{2018}]+$/u', '', $h) ?? $h;
        $h = preg_replace('/\s+/u', ' ', $h) ?? $h;

        return trim($h);
    }

    /**
     * Replace rather than upsert.
     *
     * The unique index is on (sense, language, text), so an upsert keyed on the
     * pair would insert a second row whenever a meaning is corrected and leave
     * the old one standing beside it. A word must not end up with two
     * translations because one of them used to be wrong.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function flush(array $rows): int
    {
        // Two catalogue entries can reach one sense with the same meaning - the
        // exact headword and the same word wearing a scanner's label - and the
        // unique index covers exactly that triple, so the duplicate has to go
        // before the insert rather than after it.
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['vocabulary_sense_id'].'|'.$row['language_id'].'|'.$row['text']] = $row;
        }
        $rows = array_values($unique);

        $senseIds = array_column($rows, 'vocabulary_sense_id');

        DB::transaction(function () use ($rows, $senseIds) {
            DB::table('translations')
                ->whereIn('vocabulary_sense_id', $senseIds)
                ->where('language_id', $rows[0]['language_id'])
                ->delete();
            DB::table('translations')->insert($rows);
        });

        return count($rows);
    }
}
