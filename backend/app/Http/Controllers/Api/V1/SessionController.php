<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Exercise;
use App\Models\LearningSession;
use App\Models\SessionActivity;
use App\Services\Learning\AdaptiveLearningService;
use App\Services\Learning\SpacedRepetitionService;
use Illuminate\Http\Request;

/**
 * The daily learning session.
 *
 * Composition happens on the server. The client asks what to do next and
 * renders it; it never assembles a session itself.
 */
class SessionController extends ApiController
{
    public function __construct(
        private AdaptiveLearningService $engine,
        private SpacedRepetitionService $srs,
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

        $session = $this->engine->buildNextSession($userId, $request->integer('minutes') ?: null);

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
        $this->finish($session, $request->integer('seconds'));

        return $this->ok($this->present($session->fresh(['activities'])));
    }

    private function finish(LearningSession $session, ?int $seconds = null): void
    {
        if ($session->status === 'completed') {
            return;
        }
        $elapsed = $seconds ?: ($session->started_at ? now()->diffInSeconds($session->started_at) : 0);
        $session->update([
            'status' => 'completed',
            'actual_seconds' => abs((int) $elapsed),
            'completed_at' => now(),
        ]);
    }

    private function assertOwned(Request $request, LearningSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }

    private function present(LearningSession $session): array
    {
        return [
            'id' => $session->id,
            'status' => $session->status,
            'kind' => $session->kind,
            'planned_minutes' => $session->planned_minutes,
            'composition' => $session->composition,
            'activities_planned' => $session->activities_planned,
            'activities_completed' => $session->activities_completed,
            'activities' => $session->activities->map(fn (SessionActivity $a) => [
                'id' => $a->id,
                'position' => $a->position,
                'type' => $a->activity_type,
                'status' => $a->status,
                'concept_id' => $a->concept_id,
                'predicted_success' => $a->predicted_success !== null ? (float) $a->predicted_success : null,
                // Exposed so the UI can explain *why* this appeared - and so the
                // selection model can be audited after the fact.
                'why' => $a->selection_reason,
                'subject' => $this->subjectPayload($a),
            ])->values(),
        ];
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
                'estimated_seconds' => $subject->estimated_seconds,
            ],
            default => ['kind' => class_basename($a->subject_type), 'id' => $subject->id],
        };
    }
}
