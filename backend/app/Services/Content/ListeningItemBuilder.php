<?php

namespace App\Services\Content;

use App\Models\Concept;
use App\Models\Definition;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use App\Models\Lesson;
use App\Models\VocabularySense;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A listening item the recording can actually answer.
 *
 * The block promised "listen and choose what you hear" and gave the learner
 * nothing to choose from: the builder wrote a list of concept ids the client has
 * never read, and attached no exercise, so every one of the 1,662 lessons with a
 * recording showed a play button and a Continue button under an instruction
 * describing a different screen.
 *
 * What makes an honest item here is the recording's own scope. It is the book
 * reading this page aloud, so a word printed on the page is a word the learner
 * hears, and a word that is not printed there is one they do not. The answer
 * comes from the first set and every wrong answer from the second -
 * DistractorPolicy already refuses a candidate that appears in the text it is
 * given, so it is given the page.
 *
 * Nothing is invented where those two conditions cannot both be met. The block
 * then says listen and follow, which is what it can honestly ask for.
 */
class ListeningItemBuilder
{
    /** Every lesson and unit title, lowercased, loaded once. */
    private ?Collection $headings = null;

    public function __construct(
        private DistractorPolicy $distractors,
        private SentenceQuality $quality,
    ) {}

    /**
     * @param  Collection<int, Concept>  $concepts
     * @param  callable(Concept): array<int, array{term: string, definition: ?string, module_id: ?int}>  $candidatesFor
     *                                                                                                                   wrong answers to draw from, which must reach beyond this lesson:
     *                                                                                                                   its own words are all printed on the page and so are all heard
     */
    public function for(
        Lesson $lesson,
        Collection $concepts,
        callable $candidatesFor,
        ?int $moduleId,
        string $pageText,
        int $templateId,
    ): ?Exercise {
        if (trim($pageText) === '') {
            return null;
        }

        foreach ($concepts as $concept) {
            $term = trim((string) $concept->label);

            if (! $this->distractors->isUsableTerm($term)
                || $this->isAHeading($term)
                || ! $this->quality->containsTerm($pageText, $term)) {
                continue;
            }

            $chosen = $this->distractors->choose(
                $term,
                $this->definitionOf($concept),
                // The page, so nothing printed on it can become a wrong answer.
                $pageText,
                $candidatesFor($concept),
                3,
                $moduleId,
            );

            if ($chosen === null) {
                continue;
            }

            return $this->write($lesson, $concept, $term, $chosen, $templateId);
        }

        return null;
    }

    /**
     * @param  array{options: array<int, string>, grade: string}  $chosen
     */
    private function write(Lesson $lesson, Concept $concept, string $term, array $chosen, int $templateId): Exercise
    {
        $exercise = Exercise::updateOrCreate(
            [
                'lesson_id' => $lesson->id,
                'exercise_template_id' => $templateId,
                'source_reference' => "derived:{$concept->id}:listen_and_choose",
            ],
            [
                'language_id' => $concept->language_id,
                'skill_id' => $concept->skill_id,
                'cefr_level_id' => $concept->cefr_level_id,
                'stem' => 'Which of these words do you hear in the recording?',
                'instructions' => 'Play the recording, then choose the word you heard.',
                'difficulty' => $concept->difficulty,
                'status' => 'approved',
                'generation_method' => 'derived',
                'copyright_status' => $lesson->copyright_status,
                'source_document_id' => $lesson->source_document_id,
                'source_page' => $lesson->source_page,
                'validation_score' => $chosen['grade'] === DistractorPolicy::PROVEN ? 1.000 : 0.600,
            ],
        );

        DB::table('exercise_concepts')->insertOrIgnore([
            'exercise_id' => $exercise->id, 'concept_id' => $concept->id,
            'weight' => 1.000, 'is_primary' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $options = collect($chosen['options'])->push($term)->values()->all();
        // Deterministic shuffle: the same build produces the same paper twice,
        // which is what makes a regression in item quality visible in a diff.
        usort($options, fn ($a, $b) => crc32($concept->id.'listen'.$a) <=> crc32($concept->id.'listen'.$b));

        ExerciseOption::where('exercise_id', $exercise->id)->delete();
        foreach ($options as $i => $option) {
            ExerciseOption::create([
                'exercise_id' => $exercise->id,
                'position' => $i,
                'text' => $option,
                'is_correct' => Str::lower($option) === Str::lower($term),
            ]);
        }

        ExerciseAnswer::updateOrCreate(
            ['exercise_id' => $exercise->id, 'blank_index' => 0, 'value' => $term],
            ['match_mode' => 'exact', 'is_primary' => true, 'credit' => 1.000],
        );

        return $exercise;
    }

    /**
     * Is this a section title that reached the catalogue as a headword?
     *
     * The answer has to be a word the book teaches. "Synonyms and antonyms" is
     * printed on the page and is in the recording, so both of this item's tests
     * pass, and it is still the lesson's own heading rather than anything a
     * learner is meant to have learnt.
     */
    private function isAHeading(string $term): bool
    {
        if ($this->headings === null) {
            $this->headings = DB::table('lessons')->whereNull('deleted_at')->pluck('title')
                ->merge(DB::table('units')->pluck('title'))
                ->map(fn ($t) => Str::lower(trim((string) $t)))
                ->filter()->flip();
        }

        return $this->headings->has(Str::lower(trim($term)));
    }

    /** The gloss the catalogue carries for this concept, if it carries one. */
    private function definitionOf(Concept $concept): ?string
    {
        if (! $concept->conceptable instanceof VocabularySense) {
            return null;
        }

        return DB::table('definitions')
            ->where('vocabulary_sense_id', $concept->conceptable->id)
            ->where('generation_method', '!=', Definition::AMBIGUOUS)
            ->orderBy('id')
            ->value('text');
    }
}
