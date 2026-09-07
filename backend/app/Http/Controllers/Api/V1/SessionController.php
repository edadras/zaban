<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Exercise;
use App\Models\LearningSession;
use App\Models\SessionActivity;
use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\ProgressService;
use App\Services\Learning\SessionShape;
use App\Services\Learning\SpacedRepetitionService;
use App\Services\Media\MediaPresenter;
use Illuminate\Http\Request;

/**
 * The daily learning session.
 *
 * Composition happens on the server. The client asks what to do next and
 * renders it; it never assembles a session itself.
 */
class SessionController extends ApiController
{
    /** Budget for one question, for the estimate shown against a phase. */
    private const SECONDS_PER_QUESTION = 25;

    public function __construct(
        private AdaptiveLearningService $engine,
        private SpacedRepetitionService $srs,
        private MediaPresenter $media,
        private ProgressService $progress,
    ) {}

    /** The active session, or a freshly composed one. */
    public function next(Request $request)
    {
        $userId = $request->user()->id;

        $session = LearningSession::with(['activities' => fn ($q) => $q->orderBy('position')])
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->latest('id')
            ->first();

        if (! $session) {
            $session = $this->engine->buildNextSession(
                $userId,
                $request->integer('minutes') ?: null,
                $request->string('focus')->toString() ?: null,
            );
            $session->load(['activities' => fn ($q) => $q->orderBy('position')]);
        }

        return $this->ok($this->present($session), [
            'due_reviews' => $this->srs->dueCount($userId),
        ]);
    }

    /** Force a new session, abandoning any in progress. */
    public function start(Request $request)
    {
        $userId = $request->user()->id;

        LearningSession::where('user_id', $userId)
            ->where('status', 'active')
            ->update(['status' => 'abandoned']);

        $session = $this->engine->buildNextSession(
            $userId,
            $request->integer('minutes') ?: null,
            $request->string('focus')->toString() ?: null,
        );

        return $this->created($this->present($session->load(['activities' => fn ($q) => $q->orderBy('position')])));
    }

    public function show(Request $request, LearningSession $session)
    {
        $this->assertOwned($request, $session);

        return $this->ok($this->present(
            $session->load(['activities' => fn ($q) => $q->orderBy('position')])
        ));
    }

    /** Mark one activity done and report what remains. */
    public function completeActivity(Request $request, LearningSession $session, SessionActivity $activity)
    {
        $this->assertOwned($request, $session);
        if ($activity->learning_session_id !== $session->id) {
            return $this->fail('activity_mismatch', 'That activity is not part of this session.', 422);
        }

        $activity->update(['status' => 'completed', 'completed_at' => now()]);
        $session->increment('activities_completed');

        $remaining = $session->activities()->where('status', 'pending')->count();
        if ($remaining === 0) {
            $this->finish($session);
        }

        return $this->ok([
            'activity_id' => $activity->id,
            'remaining' => $remaining,
            'session_status' => $session->fresh()->status,
        ]);
    }

    public function complete(Request $request, LearningSession $session)
    {
        $this->assertOwned($request, $session);
        $this->finish($session, $request->integer('seconds') ?: null);

        return $this->ok($this->present($session->fresh(['activities'])));
    }

    private function finish(LearningSession $session, ?int $seconds = null): void
    {
        $elapsed = $seconds;
        if ($elapsed === null || $elapsed <= 0) {
            $elapsed = $session->started_at ? now()->diffInSeconds($session->started_at) : (int) $session->actual_seconds;
        }
        $elapsed = abs((int) $elapsed);

        if ($session->status === 'completed') {
            // Last activity often auto-finishes before the client POSTs seconds;
            // accept a later duration so study time is not stuck at ~0.
            if ($elapsed > (int) $session->actual_seconds) {
                $session->update(['actual_seconds' => $elapsed]);
                $this->progress->recordSessionCompleted($session->fresh());
            }

            return;
        }

        $session->update([
            'status' => 'completed',
            'actual_seconds' => $elapsed,
            'completed_at' => now(),
        ]);

        $this->progress->recordSessionCompleted($session->fresh());
    }

    private function assertOwned(Request $request, LearningSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }

    private function present(LearningSession $session): array
    {
        $activities = $session->activities->map(fn (SessionActivity $a) => [
            'id' => $a->id,
            'position' => $a->position,
            'phase' => $a->phase,
            'type' => $a->activity_type,
            'status' => $a->status,
            'concept_id' => $a->concept_id,
            'predicted_success' => $a->predicted_success !== null ? (float) $a->predicted_success : null,
            // One line the learner can read, saying why this is in front of
            // them. Without it a session is a list of tasks with no argument.
            'rationale' => $a->rationale,
            // The machine-readable version of the same decision, so the
            // selection model can be audited after the fact.
            'why' => $a->selection_reason,
            'subject' => $this->subjectPayload($a),
        ])->values();

        return [
            'id' => $session->id,
            'status' => $session->status,
            'kind' => $session->kind,
            'planned_minutes' => $session->planned_minutes,
            'actual_seconds' => (int) $session->actual_seconds,
            'xp_earned' => (int) $session->xp_earned,
            'composition' => $session->composition,
            'activities_planned' => $session->activities_planned,
            'activities_completed' => $session->activities_completed,
            // The session's shape, so the client can show what is coming rather
            // than revealing it one activity at a time.
            'plan' => $this->plan($session),
            'activities' => $activities,
        ];
    }

    /**
     * The named parts of this session, in order, with what each one holds.
     *
     * Only phases that actually have work are listed - a learner with nothing
     * due should not be shown an empty "Consolidate" heading.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plan(LearningSession $session): array
    {
        $byPhase = $session->activities->groupBy('phase');

        $plan = [];
        foreach (SessionShape::order() as $phase) {
            $items = $byPhase->get($phase, collect());
            if ($items->isEmpty()) {
                continue;
            }

            $plan[] = [
                'phase' => $phase,
                'title' => SessionShape::title($phase),
                'purpose' => SessionShape::purpose($phase),
                'activities' => $items->count(),
                'completed' => $items->where('status', 'completed')->count(),
                'estimated_seconds' => $this->estimateSeconds($items),
            ];
        }

        return $plan;
    }

    /**
     * Roughly how long a phase takes: a block states its own duration, and a
     * question is budgeted at the median a learner spends on one.
     */
    private function estimateSeconds($activities): int
    {
        $blockIds = $activities
            ->where('subject_type', \App\Models\LessonBlock::class)
            ->pluck('subject_id')
            ->filter();

        $blockSeconds = $blockIds->isEmpty()
            ? 0
            : (int) \App\Models\LessonBlock::whereIn('id', $blockIds)->sum('estimated_seconds');

        $questions = $activities->count() - $blockIds->count();

        return $blockSeconds + $questions * self::SECONDS_PER_QUESTION;
    }

    /** Enough of the subject for the client to render without another round trip. */
    private function subjectPayload(SessionActivity $a): ?array
    {
        if (! $a->subject_type || ! $a->subject_id) {
            return null;
        }

        $subject = $a->subject_type::find($a->subject_id);
        if (! $subject) {
            return null;
        }

        return match ($a->subject_type) {
            Exercise::class => [
                'kind' => 'exercise',
                'id' => $subject->id,
                'template' => $subject->template?->code,
                'stem' => $subject->stem,
                'instructions' => $subject->instructions,
                'difficulty' => (float) $subject->difficulty,
                'options' => $subject->options()->orderBy('position')->get()
                    // Correctness is never sent to the client - grading is server-side.
                    ->map(fn ($o) => ['id' => $o->id, 'position' => $o->position, 'text' => $o->text])->values(),
            ],
            \App\Models\LessonBlock::class => [
                'kind' => 'lesson_block',
                'id' => $subject->id,
                'type' => $subject->type,
                'title' => $subject->title,
                'instructions' => $subject->instructions,
                'config' => $subject->config,
                'media_asset_id' => $subject->media_asset_id,
                // Resolve artwork / primary media so the client can paint an
                // Image.network without a second round trip. Without this the
                // study image_scene is a blank muted rectangle.
                'media' => $this->media->presentId($subject->media_asset_id),
                'audio' => $this->media->presentId(
                    $subject->config['audio_media_asset_id'] ?? null,
                ),
                'estimated_seconds' => $subject->estimated_seconds,
            ],
            \App\Models\ConversationScenario::class => [
                'kind' => 'conversation_scenario',
                'id' => $subject->id,
                'title' => $subject->title,
                'setting' => $subject->setting,
                'situation' => $subject->situation,
                'learner_role' => $subject->learner_role,
            ],
            default => ['kind' => class_basename($a->subject_type), 'id' => $subject->id],
        };
    }
}
