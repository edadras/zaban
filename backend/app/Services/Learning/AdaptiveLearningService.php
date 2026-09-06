<?php

namespace App\Services\Learning;

use App\Models\Concept;
use App\Models\Exercise;
use App\Models\LearnerConcept;
use App\Models\LearnerError;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use App\Models\Lesson;
use App\Models\SessionActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides what the learner does next.
 *
 * buildNextSession composes an ordered set of activities from competing demands -
 * due reviews, curriculum progress, known weaknesses, speaking practice and
 * variety - rather than walking a fixed lesson list. The weights shift with the
 * learner's state: someone with a large review backlog gets more review; someone
 * who has been getting things wrong gets an easier, shorter session.
 */
class AdaptiveLearningService
{
    /**
     * How far above a learner's ability a lesson may be pitched and still be
     * worth starting. Roughly the top of the 70-85% success band the spec
     * asks for: far enough to be new, close enough to be learnable.
     */
    private const REACH = 1.0;

    /** Extra blocks to read so the discarded scene takes do not cost cards. */
    private const SPARE_SCENES = 4;

    public function __construct(
        private CoursePlacementService $courses,
        private MasteryService $mastery,
        private SpacedRepetitionService $srs,
        private DifficultyService $difficulty,
        private RemediationService $remediation,
    ) {}

    public function buildNextSession(int $userId, ?int $minutes = null): LearningSession
    {
        $profile = LearnerProfile::firstOrCreate(['user_id' => $userId], [
            'language_id' => \App\Models\Language::where('code', 'en')->value('id'),
        ]);
        $minutes = $minutes ?: $this->plannedMinutes($userId);

        $due = $this->srs->dueCount($userId);
        $slots = SessionShape::slots($minutes, $due, (float) $profile->frustration_index);

        // The lesson is chosen once and drives two phases: what is studied, and
        // what is practised straight afterwards. Before this, the curriculum
        // phase handed over reading material and the questions in the same
        // session came from the review queue - so the learner was studying one
        // thing and being tested on another, which is what made the screen feel
        // like a quiz rather than a lesson.
        $lesson = $this->nextLesson($userId, $profile);

        return DB::transaction(function () use ($userId, $profile, $minutes, $slots, $lesson, $due) {
            $session = LearningSession::create([
                'user_id' => $userId,
                'course_version_id' => $profile->active_course_version_id,
                'status' => 'active',
                'kind' => 'daily',
                'composition' => [
                    'slots' => $slots,
                    'due_reviews' => $due,
                    'lesson_id' => $lesson?->id,
                    'lesson' => $lesson?->title,
                ],
                'planned_minutes' => $minutes,
                'started_at' => now(),
            ]);

            $byPhase = [
                SessionShape::WARM_UP => $this->warmUpActivities($userId, $slots[SessionShape::WARM_UP]),
                SessionShape::STUDY => $this->studyActivities($lesson, $slots[SessionShape::STUDY]),
                SessionShape::PRACTISE => $this->practiseActivities($userId, $lesson, $slots[SessionShape::PRACTISE]),
                SessionShape::USE => $this->useActivities($userId, $lesson, $slots[SessionShape::USE]),
                SessionShape::CONSOLIDATE => $this->consolidateActivities($userId, $slots[SessionShape::CONSOLIDATE]),
            ];

            // A learner who opens the app must always get something to do. If
            // every phase came back empty - a brand-new account, a narrow
            // ability band, a course with no blocks yet - fall back to the best
            // available material rather than handing back an empty session.
            if (collect($byPhase)->every(fn ($p) => $p->isEmpty())) {
                $byPhase[SessionShape::PRACTISE] = $this->fallbackActivities(
                    $userId,
                    max(4, (int) round($minutes * 0.6)),
                );
            }

            $position = 0;
            foreach (SessionShape::order() as $phase) {
                $withinPhase = 0;
                foreach ($byPhase[$phase] ?? [] as $a) {
                    SessionActivity::create([
                        'learning_session_id' => $session->id,
                        'position' => $position++,
                        'phase' => $phase,
                        'phase_position' => $withinPhase++,
                        'activity_type' => $a['type'],
                        'subject_type' => $a['subject_type'] ?? null,
                        'subject_id' => $a['subject_id'] ?? null,
                        'concept_id' => $a['concept_id'] ?? null,
                        'selection_reason' => $a['reason'],
                        'rationale' => $a['rationale'] ?? null,
                        'priority_score' => $a['priority'] ?? null,
                        'predicted_success' => $a['predicted'] ?? null,
                        'status' => 'pending',
                    ]);
                }
            }

            $session->update(['activities_planned' => $position]);

            return $session->fresh('activities');
        });
    }

    /**
     * Open on ground the learner already holds.
     *
     * Two items at most, and the ones closest to being forgotten - enough to
     * start moving without the session opening on a wall.
     */
    private function warmUpActivities(int $userId, int $count): Collection
    {
        return $this->reviewActivities($userId, min($count, 2))
            ->map(function (array $a) {
                $a['rationale'] = 'You learned this before — a quick check to start.';

                return $a;
            });
    }

    /**
     * The lesson itself, in the order the page is laid out: the text first, then
     * the picture, then the words it teaches.
     */
    private function studyActivities(?Lesson $lesson, int $count): Collection
    {
        if ($lesson === null || $count <= 0) {
            return collect();
        }

        $teaching = ['source_text', 'image_scene', 'flashcard'];

        $blocks = $lesson->blocks()
            ->whereIn('type', $teaching)
            ->orderByRaw('FIELD(type, ?, ?, ?)', $teaching)
            ->orderBy('position')
            ->limit($count + self::SPARE_SCENES)
            ->get()
            // A lesson can carry several takes of its scene. One sets the
            // picture; three in a row is a gallery, not a lesson.
            ->groupBy('type')
            ->map(fn ($group, $type) => $type === 'image_scene' ? $group->take(1) : $group)
            ->flatten()
            ->sortBy(fn ($b) => array_search($b->type, $teaching, true) * 1000 + $b->position)
            ->take($count)
            ->values();

        return $blocks->map(fn ($block) => [
            'type' => 'lesson_block',
            'subject_type' => \App\Models\LessonBlock::class,
            'subject_id' => $block->id,
            'concept_id' => $block->config['concept_id'] ?? null,
            'priority' => 50.0,
            'rationale' => match ($block->type) {
                'source_text' => "The lesson: {$lesson->title}.",
                'image_scene' => 'The scene this lesson is about.',
                'flashcard' => 'A new word from this lesson.',
                default => "From {$lesson->title}.",
            },
            'reason' => [
                'driver' => 'curriculum',
                'lesson_id' => $lesson->id,
                'lesson' => $lesson->title,
                'block_type' => $block->type,
            ],
        ])->values();
    }

    /**
     * Questions on the words the study phase has just introduced.
     *
     * This phase did not exist. The curriculum bucket handed over reading
     * material and stopped there, so nothing in a session ever asked about the
     * lesson the session had just taught - every question came from the review
     * queue or the weakness list, about words met on some other day.
     */
    private function practiseActivities(int $userId, ?Lesson $lesson, int $count): Collection
    {
        if ($lesson === null || $count <= 0) {
            return collect();
        }

        $ability = $this->difficulty->abilityFor($userId);
        $out = collect();

        foreach ($lesson->concepts()->where('concepts.is_active', true)->get() as $concept) {
            if ($out->count() >= $count) {
                break;
            }

            $exercise = $this->pickExerciseForConcept($userId, $concept->id);
            if (! $exercise) {
                continue;
            }

            $out->push([
                'type' => 'practice',
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'concept_id' => $concept->id,
                'priority' => 60.0,
                'predicted' => $this->difficulty->successProbability($ability, (float) $exercise->difficulty),
                'rationale' => "Using \"{$concept->label}\" from this lesson.",
                'reason' => [
                    'driver' => 'practice_after_study',
                    'lesson_id' => $lesson->id,
                    'label' => $concept->label,
                ],
            ]);
        }

        return $out->values();
    }

    /**
     * Hearing it, saying it, and having a conversation in it.
     *
     * The roleplay scenarios existed on their own screen and were never part of
     * a session, so a learner had to know to go and find them.
     */
    private function useActivities(int $userId, ?Lesson $lesson, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $out = collect();

        if ($lesson) {
            $blocks = $lesson->blocks()
                ->whereIn('type', ['listen_and_choose', 'repeat_after_speaker'])
                ->orderBy('position')
                ->limit(max(1, $count - 1))
                ->get();

            foreach ($blocks as $block) {
                $out->push([
                    'type' => $block->type === 'listen_and_choose' ? 'listening' : 'speaking',
                    'subject_type' => \App\Models\LessonBlock::class,
                    'subject_id' => $block->id,
                    'concept_id' => null,
                    'priority' => 40.0,
                    'rationale' => $block->type === 'listen_and_choose'
                        ? 'Hear these words spoken by the book’s own recording.'
                        : 'Say them yourself and get your pronunciation scored.',
                    'reason' => [
                        'driver' => 'use_in_context',
                        'lesson_id' => $lesson->id,
                        'block_type' => $block->type,
                    ],
                ]);
            }
        }

        // A grammar or pronunciation question from the strand book at this
        // learner's level. The corpus is six series now, but a session is
        // built around one lesson of one of them, so without this a learner
        // placed on the vocabulary spine never meets a grammar item at all -
        // which is exactly what the platform was asked about: where does it
        // teach grammar?
        if ($out->count() < $count) {
            $out = $out->concat($this->strandActivities($userId, $count - $out->count()));
        }

        if ($out->count() < $count) {
            $out = $out->concat($this->conversationActivity($userId));
        }

        if ($out->isEmpty()) {
            $out = $this->speakingActivities($userId, $count)->map(function (array $a) {
                $a['rationale'] = 'Speaking practice on the sounds you find hardest.';

                return $a;
            });
        }

        return $out->take($count)->values();
    }

    /**
     * A question from each of the other series, at the level this learner is at.
     *
     * The books are a ladder per subject: a learner placed at B2 has a B2
     * grammar book and a B2 pronunciation book waiting, and no reason to work
     * through the elementary ones to reach them. One item from each strand,
     * hardest-first within reach, so a session that studies vocabulary still
     * asks a grammar question.
     *
     * Silent when a strand has nothing servable, which is the honest state for
     * the books whose exercises are still printed prose.
     */
    private function strandActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $profile = LearnerProfile::where('user_id', $userId)->first();
        if (! $profile) {
            return collect();
        }

        $spine = $profile->active_course_version_id;
        $ability = $this->difficulty->abilityFor($userId);
        $strands = $this->courses->strandsForAbility(
            $profile->ability === null ? null : (float) $profile->ability,
        );

        $out = collect();
        foreach ($strands as $series => $versionId) {
            if ($out->count() >= $count || $versionId === $spine) {
                continue;
            }

            $exercise = $this->difficulty->choose(
                $this->answerable(
                    Exercise::query()
                        ->join('lessons', 'lessons.id', '=', 'exercises.lesson_id')
                        ->join('units', 'units.id', '=', 'lessons.unit_id')
                        ->join('modules', 'modules.id', '=', 'units.module_id')
                        ->where('modules.course_version_id', $versionId),
                )->select('exercises.*')->distinct()->limit(25)->get(),
                $ability,
            );

            if (! $exercise) {
                continue;
            }

            $out->push([
                'type' => 'practice',
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'concept_id' => $exercise->concepts()->value('concepts.id'),
                'priority' => 38.0,
                'predicted' => $this->difficulty->successProbability($ability, (float) $exercise->difficulty),
                'rationale' => 'A '.str_replace('_', ' ', $series).' question at your level.',
                'reason' => [
                    'driver' => 'other_strand',
                    'series' => $series,
                    'course_version_id' => $versionId,
                ],
            ]);
        }

        return $out->values();
    }

    /**
     * One roleplay pitched at the learner's level, if there is one.
     */
    private function conversationActivity(int $userId): Collection
    {
        $level = LearnerProfile::where('user_id', $userId)->value('current_cefr_level_id');

        $scenario = DB::table('conversation_scenarios')
            ->when($level, fn ($q) => $q->where('cefr_level_id', '<=', $level))
            ->orderByDesc('cefr_level_id')
            ->inRandomOrder()
            ->first();

        if (! $scenario) {
            return collect();
        }

        return collect([[
            'type' => 'conversation',
            'subject_type' => \App\Models\ConversationScenario::class,
            'subject_id' => $scenario->id,
            'concept_id' => null,
            'priority' => 35.0,
            'rationale' => "Talk it through: {$scenario->title}.",
            'reason' => [
                'driver' => 'conversation_practice',
                'scenario' => $scenario->title,
            ],
        ]]);
    }

    /**
     * Everything outstanding: what spaced repetition says is due, and what the
     * mastery model says keeps going wrong.
     */
    private function consolidateActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $reviewSlots = (int) ceil($count * 0.6);

        $reviews = $this->reviewActivities($userId, $reviewSlots)
            ->map(function (array $a) {
                $a['rationale'] = 'Due for review today.';

                return $a;
            });

        $weakness = $this->weaknessActivities($userId, $count - $reviews->count())
            ->map(function (array $a) {
                $a['rationale'] = $a['reason']['strategy'] === 'retest'
                    ? 'You have missed this one before.'
                    : 'A different way in, since the last approach did not stick.';

                return $a;
            });

        $out = $reviews->concat($weakness);

        // A learner with nothing due and nothing weak still gets to meet
        // something new rather than finishing early.
        if ($out->count() < $count) {
            $out = $out->concat(
                $this->explorationActivities($userId, $count - $out->count())
                    ->map(function (array $a) {
                        $a['rationale'] = 'Something new, near where you are now.';

                        return $a;
                    }),
            );
        }

        return $out->take($count)->values();
    }

    private function plannedMinutes(int $userId): int
    {
        return (int) (DB::table('user_settings')->where('user_id', $userId)->value('daily_target_minutes') ?: 15);
    }

    private function reviewActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        return $this->srs->due($userId, $count)->map(function (LearnerConcept $lc) use ($userId) {
            $exercise = $this->pickExerciseForConcept($userId, $lc->concept_id);

            return [
                'type' => 'review',
                'subject_type' => $exercise ? Exercise::class : null,
                'subject_id' => $exercise?->id,
                'concept_id' => $lc->concept_id,
                'priority' => round($this->srs->forgettingProbability($lc) * 100, 4),
                'predicted' => $exercise ? $this->difficulty->successProbability(
                    $this->difficulty->abilityFor($userId), (float) $exercise->difficulty,
                ) : null,
                'reason' => [
                    'driver' => 'spaced_repetition',
                    'due_since' => $lc->next_review_at?->toIso8601String(),
                    'forgetting_probability' => $this->srs->forgettingProbability($lc),
                    'mastery' => (float) $lc->mastery_score,
                ],
            ];
        })->filter(fn ($a) => $a['subject_id'] !== null)->values();
    }

    private function weaknessActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $out = collect();
        foreach ($this->mastery->weakest($userId, $count * 2) as $lc) {
            if ($out->count() >= $count) {
                break;
            }

            // Repeated failure means the same question again will not help.
            $plan = $this->remediation->planFor($userId, $lc);
            $exercise = $plan['exercise'] ?? $this->pickExerciseForConcept($userId, $lc->concept_id, $plan['exclude_ids'] ?? []);
            if (! $exercise) {
                continue;
            }

            $out->push([
                'type' => $plan['strategy'] === 'retest' ? 'weakness' : 'remediation',
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'concept_id' => $lc->concept_id,
                'priority' => round((1 - (float) $lc->mastery_score) * 100, 4),
                'predicted' => $this->difficulty->successProbability(
                    $this->difficulty->abilityFor($userId), (float) $exercise->difficulty,
                ),
                'reason' => [
                    'driver' => 'weakness',
                    'mastery' => (float) $lc->mastery_score,
                    'incorrect_count' => $lc->incorrect_count,
                    'strategy' => $plan['strategy'],
                ],
            ]);
        }

        return $out;
    }

    private function curriculumActivities(int $userId, LearnerProfile $profile, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        $lesson = $this->nextLesson($userId, $profile);
        if (! $lesson) {
            return collect();
        }

        $out = collect();
        foreach ($lesson->blocks()->orderBy('position')->limit($count)->get() as $block) {
            $out->push([
                'type' => 'lesson_block',
                'subject_type' => \App\Models\LessonBlock::class,
                'subject_id' => $block->id,
                'concept_id' => null,
                'priority' => 50.0,
                'reason' => [
                    'driver' => 'curriculum',
                    'lesson_id' => $lesson->id,
                    'lesson' => $lesson->title,
                    'block_type' => $block->type,
                ],
            ]);
        }

        return $out;
    }

    private function speakingActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        // Prefer drills on the phonemes this learner actually gets wrong.
        $weakPhonemes = DB::table('pronunciation_errors')
            ->where('user_id', $userId)->whereNull('resolved_at')
            ->orderByDesc('error_rate')->limit(3)->pluck('phoneme_id');

        $blocks = DB::table('lesson_blocks')
            ->whereIn('type', ['repeat_after_speaker', 'pronunciation_drill', 'open_speaking'])
            ->inRandomOrder()->limit($count)->get();

        return collect($blocks)->map(fn ($b) => [
            'type' => 'speaking',
            'subject_type' => \App\Models\LessonBlock::class,
            'subject_id' => $b->id,
            'concept_id' => null,
            'priority' => 40.0,
            'reason' => [
                'driver' => 'speaking_practice',
                'targets_weak_phonemes' => $weakPhonemes->isNotEmpty(),
            ],
        ]);
    }

    private function explorationActivities(int $userId, int $count): Collection
    {
        if ($count <= 0) {
            return collect();
        }

        // Concepts at the learner's level they have never met.
        $seen = LearnerConcept::where('user_id', $userId)->pluck('concept_id');
        $ability = $this->difficulty->abilityFor($userId);

        // Widen the band until the catalogue actually yields something; a strict
        // window silently starves exploration on smaller courses.
        $concepts = collect();
        foreach ([0.9, 1.8, 3.5] as $window) {
            $concepts = Concept::when($seen->isNotEmpty(), fn ($q) => $q->whereNotIn('id', $seen))
                ->whereBetween('difficulty', [$ability - $window, $ability + $window])
                ->where('is_active', true)
                ->inRandomOrder()->limit($count)->get();
            if ($concepts->isNotEmpty()) {
                break;
            }
        }

        return $concepts->map(function (Concept $c) use ($userId) {
            $exercise = $this->pickExerciseForConcept($userId, $c->id);

            return $exercise ? [
                'type' => 'exploration',
                'subject_type' => Exercise::class,
                'subject_id' => $exercise->id,
                'concept_id' => $c->id,
                'priority' => 20.0,
                'reason' => ['driver' => 'new_material', 'label' => $c->label],
            ] : null;
        })->filter()->values();
    }

    /**
     * Last-resort material: any published, answerable item near the learner's
     * ability, widening the band until something is found. Used only when the
     * ordinary selectors all come back empty.
     */
    private function fallbackActivities(int $userId, int $count): Collection
    {
        $ability = $this->difficulty->abilityFor($userId);
        $seen = \App\Models\ExerciseAttempt::where('user_id', $userId)->pluck('exercise_id')->all();

        foreach ([1.0, 2.0, 4.0, 99.0] as $window) {
            $candidates = $this->answerable(
                Exercise::query()
                    ->join('exercise_concepts', 'exercise_concepts.exercise_id', '=', 'exercises.id')
                    ->when($seen, fn ($q) => $q->whereNotIn('exercises.id', $seen)),
            )
                ->whereBetween('exercises.difficulty', [$ability - $window, $ability + $window])
                ->select('exercises.*', 'exercise_concepts.concept_id')
                ->distinct()
                ->limit($count * 3)
                ->get();

            if ($candidates->isNotEmpty()) {
                return $candidates
                    ->unique('id')
                    ->sortBy(fn (Exercise $e) => abs(
                        $this->difficulty->successProbability($ability, (float) $e->difficulty) - 0.775
                    ))
                    ->take($count)
                    ->map(fn (Exercise $e) => [
                        'type' => 'exploration',
                        'subject_type' => Exercise::class,
                        'subject_id' => $e->id,
                        'concept_id' => $e->concept_id,
                        'priority' => 10.0,
                        'predicted' => $this->difficulty->successProbability($ability, (float) $e->difficulty),
                        'reason' => [
                            'driver' => 'fallback',
                            'note' => 'no review, weakness or curriculum material was available',
                            'search_window' => $window,
                        ],
                    ])->values();
            }
        }

        return collect();
    }

    /** Pick the best-fitting exercise for a concept at the learner's ability. */
    /**
     * Only items a learner can actually answer on their own.
     *
     * The books' own exercise sections import as one row per instruction -
     * "Answer the questions.", "Match up the pairs to make collocations." -
     * because the numbered parts beneath them belong to the printed page, not
     * to the database. They are kept as source material and marked draft. The
     * selectors were not looking at status, so a session could hand a learner
     * "Write the related adjectives in the correct columns." with nothing to
     * write in.
     */
    private function answerable($query)
    {
        return $query
            ->whereIn('exercises.status', Exercise::SERVABLE_STATUSES)
            ->whereNull('exercises.deleted_at');
    }

    public function pickExerciseForConcept(int $userId, int $conceptId, array $excludeIds = []): ?Exercise
    {
        $ability = $this->difficulty->abilityFor($userId);

        $candidates = $this->answerable(
            Exercise::query()
                ->join('exercise_concepts', 'exercise_concepts.exercise_id', '=', 'exercises.id')
                ->where('exercise_concepts.concept_id', $conceptId)
                ->when($excludeIds, fn ($q) => $q->whereNotIn('exercises.id', $excludeIds)),
        )
            ->select('exercises.*')
            ->distinct()
            ->limit(25)
            ->get();

        return $this->difficulty->choose($candidates, $ability);
    }

    /**
     * The next lesson this learner should study.
     *
     * Two things this used to ignore. It walked every lesson in the database in
     * id order, because active_course_version_id was never assigned, so the book
     * a learner was placed into had no effect. And it ignored ability entirely,
     * so the next lesson in the book was served whether or not it was within
     * reach.
     *
     * Now the learner works through their own book in the order it was written -
     * these are course books, and their order is the pedagogy - but a lesson
     * pitched well above where they currently are is passed over and comes back
     * later, once their ability has caught up with it.
     */
    private function nextLesson(int $userId, LearnerProfile $profile): ?Lesson
    {
        $version = $profile->active_course_version_id
            ?? $this->courses->assign($profile);

        if ($version === null) {
            return null;
        }

        $completed = DB::table('lesson_attempts')
            ->where('user_id', $userId)->where('status', 'completed')->pluck('lesson_id');

        $remaining = $this->lessonsInOrder($version, $completed);

        if ($remaining->isEmpty()) {
            // The book is finished. Move up rather than handing back nothing.
            $next = $this->courses->nextVersionAfter($version);
            if ($next === null || $next === $version) {
                return null;
            }
            $profile->update(['active_course_version_id' => $next]);

            $remaining = $this->lessonsInOrder($next, $completed);
            if ($remaining->isEmpty()) {
                return null;
            }
        }

        $ability = $this->difficulty->abilityFor($userId);
        $ceiling = $ability + self::REACH;

        $withinReach = $remaining->first(fn ($l) => (float) $l->difficulty <= $ceiling);

        // Everything left is above the ceiling - which happens to a learner who
        // has worked through the easy end of a book. The easiest of what is left
        // beats stopping.
        $chosen = $withinReach ?? $remaining->sortBy('difficulty')->first();

        return $chosen ? Lesson::find($chosen->id) : null;
    }

    /**
     * The lessons of one course version, in the order the book teaches them,
     * each carrying the mean difficulty of what it teaches.
     */
    private function lessonsInOrder(int $versionId, Collection $completed): Collection
    {
        return DB::table('lessons')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->join('modules', 'modules.id', '=', 'units.module_id')
            ->join('lesson_concept', 'lesson_concept.lesson_id', '=', 'lessons.id')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('modules.course_version_id', $versionId)
            // A daily session is built around a lesson that teaches words. The
            // study-skills pages at the front of each book sort first and teach
            // none, so they were the first thing every learner saw and left the
            // practice phase with nothing to ask about. They stay in the course
            // and stay browsable; they just do not drive a session.
            //
            // Stated as an exclusion rather than a requirement so that a corpus
            // which has not been classified yet still yields lessons instead of
            // silently yielding none.
            ->where('lessons.kind', '!=', 'study_skills')
            ->where('concepts.is_active', true)
            ->whereNull('lessons.deleted_at')
            ->when($completed->isNotEmpty(), fn ($q) => $q->whereNotIn('lessons.id', $completed))
            ->groupBy('lessons.id', 'modules.position', 'units.position', 'lessons.position')
            ->orderBy('modules.position')
            ->orderBy('units.position')
            ->orderBy('lessons.position')
            ->select([
                'lessons.id',
                DB::raw('AVG(concepts.difficulty) as difficulty'),
            ])
            ->get();
    }
}
