<?php

namespace Tests\Feature\Classroom;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\SpeechAttempt;
use App\Models\User;
use App\Services\Classroom\HomeworkMarker;
use App\Services\Classroom\HomeworkService;
use Tests\Feature\Ai\Support\FakeJsonTextProvider;

/**
 * The machine's first pass at homework.
 *
 * The point of routing this through the analysers the product already has is
 * that a learner's homework and their own practice are scored by the same
 * thing. A learner marked 60 on homework and 80 on identical practice has
 * learnt only that the numbers are noise, so what is tested here is that the
 * writing homework really does go through the writing analyser - and that a
 * mark the machine made never reaches the learner on its own.
 */
class HomeworkMarkingTest extends ClassroomTestCase
{
    public function test_writing_homework_is_marked_by_the_writing_analyser(): void
    {
        $this->withMarkingProvider();

        $submission = $this->handInParagraph();

        app(HomeworkMarker::class)->mark($submission);
        $submission->refresh();

        $this->assertSame(AssignmentSubmission::MARKED, $submission->status);
        // 72 out of 100 on an assignment worth 50 points.
        $this->assertSame(36.0, (float) $submission->ai_score);
        $this->assertSame('writing', $submission->ai_feedback['kind']);
        // Stored as JSON, so a whole number comes back as one.
        $this->assertEquals(72, $submission->ai_feedback['scores']['overall']);
        $this->assertNotNull($submission->ai_model);
    }

    /** The suggestion sits next to the coach's column, never in it. */
    public function test_the_machines_mark_is_not_the_learners_mark(): void
    {
        $this->withMarkingProvider();

        $submission = $this->handInParagraph();
        app(HomeworkMarker::class)->mark($submission);
        $submission->refresh();

        $this->assertNotNull($submission->ai_score);
        $this->assertNull($submission->score);
        $this->assertNull($submission->marked_by);
        $this->assertFalse($submission->isReturned());
    }

    public function test_the_coach_can_take_the_machines_number_or_their_own(): void
    {
        $this->withMarkingProvider();

        $submission = $this->handInParagraph();
        app(HomeworkMarker::class)->mark($submission);

        app(HomeworkService::class)->release($submission->refresh(), $this->coach);

        $this->assertSame(36.0, (float) $submission->fresh()->score);
        $this->assertSame($this->coach->id, $submission->fresh()->marked_by);

        $second = $this->handInParagraph($this->otherStudent);
        app(HomeworkMarker::class)->mark($second);
        app(HomeworkService::class)->release($second->refresh(), $this->coach, 45.0, 'بهتر از دفعهٔ قبل.');

        $this->assertSame(45.0, (float) $second->fresh()->score);
    }

    /** With no provider the coach marks it themselves, and is told why. */
    public function test_it_says_so_when_it_could_not_mark(): void
    {
        config(['ai.chains.text' => []]);

        $submission = $this->handInParagraph();
        app(HomeworkMarker::class)->mark($submission);
        $submission->refresh();

        // Back in the coach's pile, not stuck half-marked for ever.
        $this->assertSame(AssignmentSubmission::SUBMITTED, $submission->status);
        $this->assertNotNull($submission->ai_error);
        $this->assertNull($submission->ai_score);
    }

    // ------------------------------------------------------------- speaking

    public function test_spoken_homework_is_read_off_the_speech_attempt(): void
    {
        $assignment = $this->published(Assignment::SPEAKING);

        $attempt = SpeechAttempt::create([
            'user_id' => $this->student->id,
            'expected_text' => 'The weather was lovely.',
            'transcript' => 'The weather was lovely.',
            'status' => 'scored',
            'overall_score' => 88,
            'pronunciation_score' => 90,
            'fluency_score' => 84,
        ]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'speech_attempt_id' => $attempt->id,
            ])->assertOk();

        $submission = AssignmentSubmission::where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        app(HomeworkMarker::class)->mark($submission);
        $submission->refresh();

        // 88 per cent of an assignment worth 50 points.
        $this->assertSame(44.0, (float) $submission->ai_score);
        $this->assertSame('speaking', $submission->ai_feedback['kind']);
        $this->assertEquals(90, $submission->ai_feedback['scores']['pronunciation']);
    }

    /**
     * A recording still being analysed is waited for, not failed.
     *
     * The speech pipeline runs on its own schedule and the marker can arrive
     * first; recording a failure would leave the coach something to be told to
     * forget.
     */
    public function test_a_recording_still_being_analysed_is_waited_for(): void
    {
        $assignment = $this->published(Assignment::SPEAKING);

        $attempt = SpeechAttempt::create([
            'user_id' => $this->student->id,
            'expected_text' => 'The weather was lovely.',
            'status' => 'processing',
        ]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'speech_attempt_id' => $attempt->id,
            ]);

        $submission = AssignmentSubmission::with('speechAttempt')
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $this->student->id)->firstOrFail();

        $this->assertTrue(app(HomeworkMarker::class)->isWaiting($submission));
    }

    // ------------------------------------------------------------- helpers

    private function withMarkingProvider(): void
    {
        config([
            'ai.chains.text' => ['fake-json'],
            'ai.providers.fake-json.driver' => FakeJsonTextProvider::class,
        ]);
    }

    private function published(string $kind = Assignment::WRITING): Assignment
    {
        $response = $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/homework", [
                'kind' => $kind,
                'title' => 'مشق این هفته',
                'points' => 50,
                'due_at' => now()->addDays(3)->toIso8601String(),
            ])->assertCreated();

        $assignment = Assignment::findOrFail($response->json('data.id'));
        $this->actingAs($this->coach)->postJson("/api/v1/homework/{$assignment->id}/publish");

        return $assignment->refresh();
    }

    private function handInParagraph(?User $learner = null): AssignmentSubmission
    {
        $learner ??= $this->student;

        $assignment = $this->assignment ??= $this->published();

        $this->actingAs($learner)
            ->postJson("/api/v1/homework/{$assignment->id}/submit", [
                'body' => 'Last summer I went to Isfahan with my family and we saw the bridges.',
            ])->assertOk();

        return AssignmentSubmission::with(['assignment', 'writingAttempt'])
            ->where('assignment_id', $assignment->id)
            ->where('user_id', $learner->id)
            ->firstOrFail();
    }

    private ?Assignment $assignment = null;
}
