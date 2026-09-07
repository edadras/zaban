<?php

namespace Tests\Feature\Classroom;

use App\Jobs\AnswerClassQuestion;
use App\Models\ClassThread;
use App\Models\ClassThreadReply;
use App\Services\Classroom\ClassroomAiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Ai\Support\FakeTextProvider;

/**
 * The assistant on the class board.
 *
 * What is tested is mostly restraint. It answers a question nobody has
 * answered, it is visibly the machine talking, it does not touch an
 * announcement, and it does nothing whatsoever on an installation with no AI
 * provider - which is every installation until somebody configures one.
 */
class BoardAssistantTest extends ClassroomTestCase
{
    /** With no provider the board is exactly as it was. */
    public function test_it_does_nothing_without_a_provider(): void
    {
        config(['ai.chains.text' => []]);

        $thread = $this->question();

        $this->assertNull(app(ClassroomAiService::class)->answerThread($thread));
        $this->assertSame(0, $thread->fresh()->reply_count);
    }

    public function test_it_answers_a_question_and_says_it_is_the_assistant(): void
    {
        $this->withFakeProvider();

        $thread = $this->question();
        $reply = app(ClassroomAiService::class)->answerThread($thread);

        $this->assertNotNull($reply);
        $this->assertTrue($reply->is_ai_answer);
        // A draft until a person says otherwise.
        $this->assertFalse($reply->ai_endorsed);
        $this->assertSame(1, $thread->fresh()->reply_count);
    }

    /** The learner has to be able to tell who is talking to them. */
    public function test_the_class_sees_that_it_was_the_assistant(): void
    {
        $this->withFakeProvider();

        $thread = $this->question();
        app(ClassroomAiService::class)->answerThread($thread);

        $this->actingAs($this->student)
            ->getJson("/api/v1/threads/{$thread->id}")
            ->assertOk()
            ->assertJsonPath('data.replies.0.is_ai_answer', true)
            ->assertJsonPath('data.replies.0.ai_endorsed', false);
    }

    /** The point is to fill a silence, not to compete with the class. */
    public function test_it_keeps_out_of_the_way_once_somebody_has_answered(): void
    {
        $this->withFakeProvider();

        $thread = $this->question();

        $this->actingAs($this->otherStudent)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'چون سوم‌شخص است.']);

        $this->assertNull(app(ClassroomAiService::class)->answerThread($thread->fresh()));
    }

    public function test_it_does_not_answer_an_announcement(): void
    {
        $this->withFakeProvider();

        $thread = ClassThread::create([
            'class_group_id' => $this->group->id,
            'author_id' => $this->coach->id,
            'kind' => ClassThread::ANNOUNCEMENT,
            'title' => 'کلاس فردا تعطیل است',
            'last_activity_at' => now(),
        ]);

        $this->assertNull(app(ClassroomAiService::class)->answerThread($thread));
    }

    public function test_it_stays_off_a_thread_the_coach_took_down(): void
    {
        $this->withFakeProvider();

        $thread = $this->question();
        $this->actingAs($this->coach)->postJson("/api/v1/threads/{$thread->id}/hide");

        $this->assertNull(app(ClassroomAiService::class)->answerThread($thread->fresh()));
    }

    /**
     * The delay is the design.
     *
     * A board where the machine always answers first is a board where
     * classmates stop bothering.
     */
    public function test_asking_a_question_queues_the_assistant_rather_than_answering_at_once(): void
    {
        Queue::fake();
        config(['classroom.ai.board_assistant' => true]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", ['title' => 'یک پرسش'])
            ->assertCreated();

        Queue::assertPushed(AnswerClassQuestion::class);
    }

    public function test_nothing_is_queued_when_the_assistant_is_off(): void
    {
        Queue::fake();
        config(['classroom.ai.board_assistant' => false]);

        $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", ['title' => 'یک پرسش']);

        // Notifications and broadcasts still queue; the assistant does not.
        Queue::assertNotPushed(AnswerClassQuestion::class);
    }

    // --------------------------------------------------------- endorsement

    public function test_the_coach_puts_their_name_to_it(): void
    {
        $this->withFakeProvider();

        $thread = $this->question();
        $reply = app(ClassroomAiService::class)->answerThread($thread);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/endorse")
            ->assertOk()
            ->assertJsonPath('data.ai_endorsed', true);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/endorse", ['endorsed' => false])
            ->assertOk()
            ->assertJsonPath('data.ai_endorsed', false);
    }

    public function test_a_learner_cannot_endorse(): void
    {
        $this->withFakeProvider();

        $thread = $this->question();
        $reply = app(ClassroomAiService::class)->answerThread($thread);

        $this->actingAs($this->student)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/endorse")
            ->assertStatus(403);
    }

    /** Endorsement means something only about the machine's answers. */
    public function test_a_persons_answer_cannot_be_endorsed(): void
    {
        $thread = $this->question();

        $reply = ClassThreadReply::create([
            'class_thread_id' => $thread->id,
            'author_id' => $this->otherStudent->id,
            'body' => 'چون سوم‌شخص است.',
        ]);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/endorse")
            ->assertStatus(422);
    }

    /** It writes as an account that cannot be signed into. */
    public function test_the_assistants_account_is_not_a_way_in(): void
    {
        $assistant = app(ClassroomAiService::class)->assistantAccount();

        $this->assertSame('suspended', $assistant->status);
        $this->assertFalse(Auth::attempt([
            'email' => $assistant->email,
            'password' => 'password',
        ]));
    }

    // ------------------------------------------------------------- helpers

    private function withFakeProvider(): void
    {
        config([
            'classroom.ai.board_assistant' => true,
            'ai.chains.text' => ['fake'],
            'ai.providers.fake.driver' => FakeTextProvider::class,
        ]);
    }

    private function question(): ClassThread
    {
        return ClassThread::create([
            'class_group_id' => $this->group->id,
            'author_id' => $this->student->id,
            'kind' => ClassThread::QUESTION,
            'title' => 'چرا he don’t غلط است؟',
            'body' => 'در تمرین دیروز این را اشتباه زدم.',
            'last_activity_at' => now(),
        ]);
    }
}
