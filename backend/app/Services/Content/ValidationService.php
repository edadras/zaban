<?php

namespace App\Services\Content;

use App\Models\ContentReview;
use App\Models\Exercise;
use App\Models\Lesson;
use Illuminate\Support\Str;

/**
 * Automated quality gate for generated teaching material (spec 38).
 *
 * Runs before anything can be published. Each check is independent and returns
 * a pass/fail plus a reason; the overall score is the weighted pass rate. A
 * failing hard check blocks auto-publish outright regardless of the score,
 * because "mostly correct" is not a standard an exercise can be held to.
 */
class ValidationService
{
    /** Below this, a human must look at it whatever the checks say. */
    public const AUTO_PUBLISH_THRESHOLD = 0.90;

    /** Checks that block publication on their own. */
    private const HARD_CHECKS = ['single_correct_answer', 'no_duplicate_options', 'answerable', 'no_empty_content'];

    public function validateExercise(Exercise $exercise): array
    {
        $options = $exercise->options()->get();
        $answers = $exercise->answers()->get();
        $checks = [];

        $checks['no_empty_content'] = $this->check(
            trim((string) $exercise->stem) !== '',
            'The item has no question text.',
        );

        $checks['answerable'] = $this->check(
            $options->isNotEmpty() || $answers->isNotEmpty() || $exercise->template?->is_productive,
            'The item has neither options nor an answer key and is not marked productive, so it cannot be graded.',
        );

        if ($options->isNotEmpty()) {
            $correct = $options->where('is_correct', true)->count();
            $checks['single_correct_answer'] = $this->check(
                $correct === 1,
                $correct === 0 ? 'No option is marked correct.' : "{$correct} options are marked correct.",
            );

            $texts = $options->pluck('text')->map(fn ($t) => Str::lower(trim((string) $t)));
            $checks['no_duplicate_options'] = $this->check(
                $texts->unique()->count() === $texts->count(),
                'Two or more options are identical.',
            );

            $checks['enough_options'] = $this->check(
                $options->count() >= 3,
                'Fewer than three options makes guessing too easy.',
            );

            $checks['distractors_plausible'] = $this->check(
                $options->where('is_correct', false)->every(fn ($o) => Str::length(trim((string) $o->text)) > 0),
                'A distractor is blank.',
            );
        } else {
            // Not applicable; count as passing so the score is not penalised for
            // being the wrong shape of item.
            $checks['single_correct_answer'] = $this->check(true, null);
            $checks['no_duplicate_options'] = $this->check(true, null);
        }

        $checks['has_level'] = $this->check(
            $exercise->cefr_level_id !== null,
            'The item has no CEFR level, so it cannot be targeted.',
        );

        $checks['has_concept'] = $this->check(
            \Illuminate\Support\Facades\DB::table('exercise_concepts')
                ->where('exercise_id', $exercise->id)->exists(),
            'The item is not linked to any concept, so the adaptive engine cannot use it.',
        );

        $checks['difficulty_set'] = $this->check(
            $exercise->difficulty !== null,
            'The item has no difficulty estimate.',
        );

        $checks['stem_not_truncated'] = $this->check(
            ! Str::endsWith(trim((string) $exercise->stem), ['…', '...']),
            'The question text looks truncated.',
        );

        $checks['answer_not_in_stem'] = $this->check(
            $this->answerNotGivenAway($exercise, $options, $answers),
            'The correct answer appears in the question text.',
        );

        $checks['age_appropriate'] = $this->check(
            ! $this->containsFlaggedTerms($exercise->stem),
            'The question text contains flagged language.',
        );

        return $this->summarise($checks);
    }

    public function validateLesson(Lesson $lesson): array
    {
        $blocks = $lesson->blocks()->get();
        $checks = [];

        $checks['no_empty_content'] = $this->check(
            trim((string) $lesson->title) !== '',
            'The lesson has no title.',
        );

        $checks['has_blocks'] = $this->check(
            $blocks->isNotEmpty(),
            'The lesson has no blocks, so there is nothing to present.',
        );

        $checks['answerable'] = $this->check(
            $blocks->whereNotIn('type', ['source_text', 'image_scene'])->isNotEmpty(),
            'The lesson contains only static content - the learner has nothing to do.',
        );

        $checks['has_concept'] = $this->check(
            $lesson->concepts()->exists(),
            'The lesson teaches no concept, so progress cannot be tracked against it.',
        );

        $checks['has_level'] = $this->check(
            $lesson->cefr_level_id !== null,
            'The lesson has no CEFR level.',
        );

        $checks['has_provenance'] = $this->check(
            $lesson->generation_method !== null,
            'The lesson does not record how it was produced.',
        );

        $checks['single_correct_answer'] = $this->check(true, null);
        $checks['no_duplicate_options'] = $this->check(true, null);

        return $this->summarise($checks);
    }

    /** Run validation and persist the result on the review row. */
    public function review(Exercise|Lesson $subject): ContentReview
    {
        $result = $subject instanceof Exercise
            ? $this->validateExercise($subject)
            : $this->validateLesson($subject);

        return ContentReview::updateOrCreate(
            ['reviewable_type' => $subject::class, 'reviewable_id' => $subject->id],
            [
                'validation_score' => $result['score'],
                'validation_results' => $result['checks'],
                'auto_publishable' => $result['auto_publishable'],
                'status' => ContentReview::where('reviewable_type', $subject::class)
                    ->where('reviewable_id', $subject->id)->value('status') ?? 'draft',
            ],
        );
    }

    private function summarise(array $checks): array
    {
        $passed = collect($checks)->where('passed', true)->count();
        $total = max(1, count($checks));
        $hardFailure = collect(self::HARD_CHECKS)
            ->contains(fn ($k) => isset($checks[$k]) && ! $checks[$k]['passed']);

        $score = round($passed / $total, 3);

        return [
            'score' => $score,
            'checks' => $checks,
            'hard_failure' => $hardFailure,
            // A hard failure can never auto-publish, however high the score.
            'auto_publishable' => ! $hardFailure && $score >= self::AUTO_PUBLISH_THRESHOLD,
        ];
    }

    private function check(bool $passed, ?string $reason): array
    {
        return ['passed' => $passed, 'reason' => $passed ? null : $reason];
    }

    private function answerNotGivenAway(Exercise $exercise, $options, $answers): bool
    {
        $stem = Str::lower((string) $exercise->stem);
        // A cloze legitimately contains its own sentence, so only check items
        // where the answer is a separate token.
        if (str_contains($stem, '___')) {
            return true;
        }
        $correct = $options->firstWhere('is_correct', true)?->text ?? $answers->first()?->value;
        if (! $correct || Str::length($correct) < 4) {
            return true;
        }

        return ! str_contains($stem, Str::lower($correct));
    }

    private function containsFlaggedTerms(?string $text): bool
    {
        if (! $text) {
            return false;
        }
        // Deliberately narrow: this is a tripwire for obviously unsuitable
        // generated content, not a content filter.
        $flagged = config('content.flagged_terms', []);
        $lower = Str::lower($text);

        foreach ($flagged as $term) {
            if (str_contains($lower, Str::lower($term))) {
                return true;
            }
        }

        return false;
    }
}
