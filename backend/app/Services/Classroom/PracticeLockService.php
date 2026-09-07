<?php

namespace App\Services\Classroom;

use App\Events\Classroom\ClassroomEvent;
use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\Concept;
use App\Models\PracticeLock;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pointing a learner's own practice at what their coach just taught.
 *
 * The rest of the product decides for itself what someone should study next.
 * That is right for a learner alone with the app and wrong for one who spent
 * the afternoon in a class: the evening should be that afternoon's material,
 * not whatever the spacing algorithm would have picked.
 *
 * A lock names concepts, which is the same currency the session composer, the
 * item bank and the mastery model already use - so locking practice is a filter
 * on an existing engine rather than a second engine that has to agree with the
 * first.
 */
class PracticeLockService
{
    /**
     * Lock these learners onto what this session covered.
     *
     * @param  list<int>  $userIds  who to lock. Empty means everyone on the roll.
     * @param  list<int>|null  $conceptIds  what to lock them to. Null means "work
     *                                      it out from the session's materials".
     * @return Collection<int, PracticeLock>
     */
    public function lock(
        ClassSession $session,
        User $by,
        array $userIds = [],
        ?array $conceptIds = null,
        ?int $hours = null,
        ?string $note = null,
    ): Collection {
        $conceptIds ??= $this->conceptsTaughtIn($session);

        if ($conceptIds === []) {
            throw new ClassroomException(
                'This class has no material the practice engine can teach from yet.'
            );
        }

        $userIds = $userIds !== [] ? $userIds : $session->group->students()->pluck('users.id')->all();

        if ($userIds === []) {
            throw new ClassroomException('This class has nobody enrolled.');
        }

        $hours ??= (int) config('live.practice_lock_hours');
        $lessonIds = $session->materials()->whereNotNull('lesson_id')->pluck('lesson_id')->unique()->values()->all();

        $locks = DB::transaction(function () use ($session, $by, $userIds, $conceptIds, $lessonIds, $hours, $note) {
            $made = collect();

            foreach ($userIds as $userId) {
                // One lock at a time per learner. A second class the same day
                // replaces the first rather than fighting it.
                PracticeLock::query()->active()->where('user_id', $userId)
                    ->update(['released_at' => now()]);

                $made->push(PracticeLock::create([
                    'user_id' => $userId,
                    'class_session_id' => $session->id,
                    'created_by' => $by->id,
                    'concept_ids' => array_values(array_unique($conceptIds)),
                    'lesson_ids' => $lessonIds,
                    'note' => $note,
                    'starts_at' => now(),
                    'expires_at' => now()->addHours($hours),
                ]));
            }

            return $made;
        });

        event(new ClassroomEvent($session->id, ClassroomEvent::PRACTICE_LOCKED, [
            'user_ids' => array_values($userIds),
            'concept_count' => count($conceptIds),
            'expires_at' => now()->addHours($hours)->toIso8601String(),
        ]));

        return $locks;
    }

    /** Let them roam again. */
    public function release(ClassSession $session, array $userIds = []): int
    {
        $query = PracticeLock::query()->active()->where('class_session_id', $session->id);

        if ($userIds !== []) {
            $query->whereIn('user_id', $userIds);
        }

        $released = $query->update(['released_at' => now()]);

        event(new ClassroomEvent($session->id, ClassroomEvent::PRACTICE_RELEASED, [
            'user_ids' => array_values($userIds),
        ]));

        return $released;
    }

    /**
     * The concepts behind a session's materials.
     *
     * A material that names a lesson brings that lesson's concepts; one that
     * names an exercise brings the exercise's. A video the coach uploaded
     * brings nothing, which is honest - the system has no idea what is in it,
     * and inventing concepts for it would point the evening's practice at
     * whatever happened to be lying around.
     *
     * @return list<int>
     */
    public function conceptsTaughtIn(ClassSession $session): array
    {
        $materials = $session->materials()->get();

        $lessonIds = $materials->pluck('lesson_id')->filter()->unique()->values();
        $exerciseIds = $materials->pluck('exercise_id')->filter()->unique()->values();

        $fromLessons = $lessonIds->isEmpty() ? collect() : DB::table('lesson_concept')
            ->whereIn('lesson_id', $lessonIds)->pluck('concept_id');

        $fromExercises = $exerciseIds->isEmpty() ? collect() : DB::table('exercise_concepts')
            ->whereIn('exercise_id', $exerciseIds)->pluck('concept_id');

        $ids = $fromLessons->merge($fromExercises)->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        // Only what the engine will actually teach. A concept the build set
        // aside as scanner debris must not become somebody's evening.
        return Concept::whereIn('id', $ids)->where('is_active', true)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    /** What one material would contribute, for the coach's own preview. */
    public function conceptsIn(ClassMaterial $material): array
    {
        if ($material->lesson_id !== null) {
            return DB::table('lesson_concept')->where('lesson_id', $material->lesson_id)
                ->pluck('concept_id')->map(fn ($id) => (int) $id)->all();
        }

        if ($material->exercise_id !== null) {
            return DB::table('exercise_concepts')->where('exercise_id', $material->exercise_id)
                ->pluck('concept_id')->map(fn ($id) => (int) $id)->all();
        }

        return [];
    }
}
