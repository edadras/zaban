<?php

namespace App\Services\Learning;

use App\Models\Concept;
use App\Models\Exercise;
use App\Models\LearnerConcept;
use App\Models\LearnerError;
use Illuminate\Support\Facades\DB;

/**
 * Decides how to re-teach something the learner keeps getting wrong.
 *
 * Showing the same question again is the one thing that reliably does not work,
 * so the strategy escalates with the number of failures: a different item first,
 * then an easier framing, then a change of modality, then explicit instruction.
 */
class RemediationService
{
    /**
     * @return array{strategy:string, exercise:?Exercise, exclude_ids:int[], guidance:?string}
     */
    public function planFor(int $userId, LearnerConcept $state): array
    {
        $recentlySeen = DB::table('exercise_attempts')
            ->join('exercise_concepts', 'exercise_concepts.exercise_id', '=', 'exercise_attempts.exercise_id')
            ->where('exercise_attempts.user_id', $userId)
            ->where('exercise_concepts.concept_id', $state->concept_id)
            ->where('exercise_attempts.answered_at', '>=', now()->subDays(3))
            ->pluck('exercise_attempts.exercise_id')
            ->unique()->values()->all();

        $failures = (int) $state->incorrect_count;
        $strategy = match (true) {
            $failures <= 1 => 'retest',
            $failures === 2 => 'different_item',
            $failures === 3 => 'easier_framing',
            $failures === 4 => 'change_modality',
            default => 'explicit_instruction',
        };

        $exercise = match ($strategy) {
            'retest', 'different_item' => $this->differentItem($state->concept_id, $recentlySeen),
            'easier_framing' => $this->easierItem($state->concept_id, $recentlySeen),
            'change_modality' => $this->otherModality($state->concept_id, $recentlySeen),
            default => null,
        };

        return [
            'strategy' => $strategy,
            'exercise' => $exercise,
            'exclude_ids' => $recentlySeen,
            'guidance' => $this->guidance($strategy, $state),
        ];
    }

    /** A different item testing the same concept. */
    private function differentItem(int $conceptId, array $exclude): ?Exercise
    {
        return $this->query($conceptId, $exclude)->inRandomOrder()->first();
    }

    /** The easiest available item, to rebuild footing. */
    private function easierItem(int $conceptId, array $exclude): ?Exercise
    {
        return $this->query($conceptId, $exclude)->orderBy('exercises.difficulty')->first();
    }

    /**
     * Same concept, different channel: if they keep failing a written cloze, try
     * recognition or listening instead of drilling production.
     */
    private function otherModality(int $conceptId, array $exclude): ?Exercise
    {
        return $this->query($conceptId, $exclude)
            ->join('exercise_templates', 'exercise_templates.id', '=', 'exercises.exercise_template_id')
            ->whereIn('exercise_templates.code', ['multiple_choice', 'listen_and_choose', 'flashcard', 'match'])
            ->orderBy('exercises.difficulty')
            ->first();
    }

    private function query(int $conceptId, array $exclude)
    {
        return Exercise::query()
            ->join('exercise_concepts', 'exercise_concepts.exercise_id', '=', 'exercises.id')
            ->where('exercise_concepts.concept_id', $conceptId)
            ->when($exclude, fn ($q) => $q->whereNotIn('exercises.id', $exclude))
            // Draft rows are the books' printed instructions, not answerable
            // items: their numbered parts live on the page, not in the database.
            ->whereIn('exercises.status', Exercise::SERVABLE_STATUSES)
            ->whereNull('exercises.deleted_at')
            ->select('exercises.*')
            ->distinct();
    }

    /** Human-readable instruction for the tutor to act on. */
    private function guidance(string $strategy, LearnerConcept $state): ?string
    {
        $label = Concept::find($state->concept_id)?->label ?? 'this item';

        return match ($strategy) {
            'retest' => null,
            'different_item' => "Ask about \"{$label}\" in a different sentence than before.",
            'easier_framing' => "Re-introduce \"{$label}\" with a simpler example, then check understanding.",
            'change_modality' => "Switch channel for \"{$label}\": recognition or listening rather than production.",
            'explicit_instruction' => "Teach \"{$label}\" directly: contrast it with what the learner keeps confusing it with, "
                .'give one clear example, then retest with a new question.',
            default => null,
        };
    }

    /**
     * Record a mistake so future sessions can use it. Repeat mistakes increment
     * rather than duplicate, which is what makes "keeps getting this wrong"
     * measurable.
     */
    public function recordError(
        int $userId,
        string $errorType,
        ?int $conceptId = null,
        ?int $skillId = null,
        ?string $input = null,
        ?string $expected = null,
        ?string $subtype = null,
        int $severity = 2,
        float $confidence = 1.0,
        ?string $note = null,
    ): LearnerError {
        $existing = LearnerError::where('user_id', $userId)
            ->where('concept_id', $conceptId)
            ->where('error_type', $errorType)
            ->where('error_subtype', $subtype)
            ->first();

        if ($existing) {
            $existing->increment('occurrence_count');
            $existing->update([
                'last_seen_at' => now(),
                'input' => $input ?? $existing->input,
                'expected' => $expected ?? $existing->expected,
                'resolved_at' => null,
                'severity' => max($severity, (int) $existing->severity),
            ]);

            return $existing->fresh();
        }

        return LearnerError::create([
            'user_id' => $userId,
            'concept_id' => $conceptId,
            'skill_id' => $skillId,
            'error_type' => $errorType,
            'error_subtype' => $subtype,
            'input' => $input,
            'expected' => $expected,
            'note' => $note,
            'severity' => $severity,
            'confidence' => $confidence,
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /** Mark errors on a concept resolved once it is demonstrably held. */
    public function resolveFor(int $userId, int $conceptId): int
    {
        return LearnerError::where('user_id', $userId)
            ->where('concept_id', $conceptId)
            ->whereNull('resolved_at')
            ->update(['resolved_at' => now()]);
    }

    /** @return \Illuminate\Support\Collection<int, LearnerError> */
    public function unresolved(int $userId, int $limit = 25)
    {
        return LearnerError::with('concept')
            ->where('user_id', $userId)
            ->whereNull('resolved_at')
            ->orderByDesc('severity')
            ->orderByDesc('occurrence_count')
            ->limit($limit)
            ->get();
    }
}
