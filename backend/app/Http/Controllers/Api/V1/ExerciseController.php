<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\LearnerProfile;
use App\Services\Learning\DifficultyService;
use App\Services\Learning\MasteryService;
use App\Services\Learning\RemediationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Answering and grading.
 *
 * Grading is entirely server-side: the client posts a response and is told what
 * happened. Answer keys never leave the server, and the client's opinion about
 * correctness is never consulted.
 */
class ExerciseController extends ApiController
{
    public function __construct(
        private MasteryService $mastery,
        private DifficultyService $difficulty,
        private RemediationService $remediation,
    ) {}

    public function show(Request $request, Exercise $exercise)
    {
        return $this->ok([
            'id' => $exercise->id,
            'template' => $exercise->template?->code,
            'stem' => $exercise->stem,
            'instructions' => $exercise->instructions,
            'payload' => $exercise->payload,
            'difficulty' => (float) $exercise->difficulty,
            'options' => $exercise->options()->orderBy('position')->get()
                ->map(fn ($o) => ['id' => $o->id, 'position' => $o->position, 'text' => $o->text])->values(),
            'hints_available' => $exercise->hints()->count(),
        ]);
    }

    /** Progressive hints, one level at a time. */
    public function hint(Request $request, Exercise $exercise)
    {
        $level = max(1, $request->integer('level', 1));
        $hint = $exercise->hints()->where('level', $level)->first();

        return $hint
            ? $this->ok(['level' => $level, 'text' => $hint->text])
            : $this->fail('no_hint', 'No hint at that level.', 404);
    }

    public function submit(Request $request, Exercise $exercise)
    {
        $data = $request->validate([
            'response' => ['required'],
            'hints_used' => ['nullable', 'integer', 'min:0', 'max:10'],
            'response_ms' => ['nullable', 'integer', 'min:0'],
            'learning_session_id' => ['nullable', 'integer', 'exists:learning_sessions,id'],
            'session_activity_id' => ['nullable', 'integer', 'exists:session_activities,id'],
        ]);

        $user = $request->user();
        $grade = $this->grade($exercise, $data['response']);
        $ability = $this->difficulty->abilityFor($user->id, $exercise->skill_id);

        $attempt = DB::transaction(function () use ($exercise, $user, $data, $grade, $ability) {
            $attemptNumber = ExerciseAttempt::where('user_id', $user->id)
                ->where('exercise_id', $exercise->id)->count() + 1;

            $attempt = ExerciseAttempt::create([
                'user_id' => $user->id,
                'exercise_id' => $exercise->id,
                'learning_session_id' => $data['learning_session_id'] ?? null,
                'session_activity_id' => $data['session_activity_id'] ?? null,
                'response' => ['value' => $data['response']],
                'is_correct' => $grade['correct'],
                'score' => $grade['score'],
                'hints_used' => $data['hints_used'] ?? 0,
                'attempt_number' => $attemptNumber,
                'response_ms' => $data['response_ms'] ?? null,
                'ability_at_attempt' => $ability,
                'predicted_success' => $this->difficulty->successProbability(
                    $ability, (float) $exercise->difficulty,
                    (float) ($exercise->discrimination ?: 1), (float) $exercise->guessing,
                ),
                'feedback' => $grade['feedback'],
                'answered_at' => now(),
            ]);

            // Live item calibration: real attempts are better evidence of an
            // item's difficulty than our initial estimate.
            $exercise->increment('attempt_count');
            if ($grade['correct']) {
                $exercise->increment('correct_count');
            }

            return $attempt;
        });

        // Mastery fans out across every concept the item tests.
        $conceptIds = DB::table('exercise_concepts')->where('exercise_id', $exercise->id)->pluck('concept_id');
        $states = [];
        foreach ($conceptIds as $conceptId) {
            $states[] = $this->mastery->record(
                $user->id, $conceptId, $grade['correct'], $grade['score'],
                $data['hints_used'] ?? 0, $data['response_ms'] ?? null, (float) $exercise->difficulty,
            );
        }

        if (! $grade['correct']) {
            $this->remediation->recordError(
                userId: $user->id,
                errorType: $this->errorTypeFor($exercise),
                conceptId: $conceptIds->first(),
                skillId: $exercise->skill_id,
                input: is_scalar($data['response']) ? (string) $data['response'] : json_encode($data['response']),
                expected: $grade['expected'],
            );
        } else {
            foreach ($conceptIds as $conceptId) {
                // Only clear the error record once the concept is genuinely held.
                $held = collect($states)->firstWhere('concept_id', $conceptId);
                if ($held && $held->mastery_score >= MasteryService::STRONG) {
                    $this->remediation->resolveFor($user->id, $conceptId);
                }
            }
        }

        $this->updateAbility($user->id, $exercise, $grade['correct']);

        return $this->ok([
            'attempt_id' => $attempt->id,
            'correct' => $grade['correct'],
            'score' => $grade['score'],
            'expected' => $grade['correct'] ? null : $grade['expected'],
            'explanation' => $exercise->explanations()->value('text'),
            'feedback' => $grade['feedback'],
            'mastery' => collect($states)->map(fn ($s) => [
                'concept_id' => $s->concept_id,
                'mastery_score' => (float) $s->mastery_score,
                'next_review_at' => $s->next_review_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * Grade against the stored key. Accepted answers may declare a match mode so
     * a near-miss can earn partial credit rather than a flat failure.
     *
     * @return array{correct:bool, score:float, expected:?string, feedback:array}
     */
    private function grade(Exercise $exercise, mixed $response): array
    {
        $answers = $exercise->answers()->get();

        // Multiple choice: the option flag is authoritative.
        $options = $exercise->options()->get();
        if ($options->isNotEmpty()) {
            $correctOption = $options->firstWhere('is_correct', true);
            $chosen = is_numeric($response)
                ? $options->firstWhere('id', (int) $response)
                : $options->first(fn ($o) => $this->normalise($o->text) === $this->normalise((string) $response));

            $correct = $chosen && $correctOption && $chosen->id === $correctOption->id;

            return [
                'correct' => (bool) $correct,
                'score' => $correct ? 1.0 : 0.0,
                'expected' => $correctOption?->text,
                'feedback' => array_filter([
                    'distractor_rationale' => (! $correct && $chosen) ? $chosen->distractor_rationale : null,
                ]),
            ];
        }

        if ($answers->isEmpty()) {
            // Open-ended item: it needs AI or human grading, so do not pretend
            // to have scored it.
            return ['correct' => false, 'score' => 0.0, 'expected' => null,
                    'feedback' => ['requires_review' => true,
                                   'message' => 'This response needs review before it can be scored.']];
        }

        $given = (string) (is_array($response) ? reset($response) : $response);
        $best = ['correct' => false, 'score' => 0.0, 'expected' => $answers->first()->value];

        foreach ($answers as $answer) {
            $credit = (float) $answer->credit;
            $matched = match ($answer->match_mode) {
                'exact' => $given === $answer->value,
                'regex' => (bool) @preg_match('/'.$answer->value.'/iu', $given),
                'fuzzy' => $this->fuzzyMatches($given, $answer->value),
                default => $this->normalise($given) === $this->normalise($answer->value),
            };
            if ($matched && $credit > $best['score']) {
                $best = ['correct' => $credit >= 1.0, 'score' => $credit, 'expected' => $answer->value];
            }
        }

        return $best + ['feedback' => []];
    }

    /** Tolerate a single typo, but not a different word. */
    private function fuzzyMatches(string $given, string $expected): bool
    {
        $a = $this->normalise($given);
        $b = $this->normalise($expected);
        if ($a === $b) {
            return true;
        }
        $allowed = mb_strlen($b) >= 6 ? 1 : 0;

        return $allowed > 0 && levenshtein($a, $b) <= $allowed;
    }

    private function normalise(string $v): string
    {
        $v = Str::lower(trim($v));
        $v = preg_replace('/[\p{P}\p{S}]/u', '', $v);

        return preg_replace('/\s+/u', ' ', $v);
    }

    private function errorTypeFor(Exercise $exercise): string
    {
        return match ($exercise->template?->code) {
            'listen_and_choose', 'listen_and_type', 'dictation' => 'listening',
            'error_correction', 'sentence_reorder' => 'grammar',
            'pronunciation_drill', 'minimal_pair', 'repeat_after_speaker' => 'pronunciation',
            'translation' => 'vocabulary_confusion',
            default => 'vocabulary_confusion',
        };
    }

    private function updateAbility(int $userId, Exercise $exercise, bool $correct): void
    {
        $profile = LearnerProfile::where('user_id', $userId)->first();
        if (! $profile) {
            return;
        }
        [$ability, $se] = $this->difficulty->updateAbility(
            (float) $profile->ability, (float) $profile->ability_se, $exercise, $correct,
        );
        $profile->update(['ability' => $ability, 'ability_se' => $se]);

        if ($exercise->skill_id) {
            $skill = \App\Models\LearnerSkillState::firstOrCreate(
                ['user_id' => $userId, 'skill_id' => $exercise->skill_id],
                ['ability' => 0, 'ability_se' => 1.5],
            );
            [$sa, $sse] = $this->difficulty->updateAbility(
                (float) $skill->ability, (float) $skill->ability_se, $exercise, $correct,
            );
            $skill->update([
                'ability' => $sa, 'ability_se' => $sse,
                'attempt_count' => $skill->attempt_count + 1,
                'last_assessed_at' => now(),
            ]);
        }
    }
}
