<?php

namespace Tests\Feature\Panel;

use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\ClassThread;
use App\Services\Classroom\ForumService;
use App\Services\Classroom\HomeworkService;

/**
 * The board and the homework, from the coach's desk.
 *
 * The one thing worth checking hardest on this side is that the marking screen
 * keeps the machine's opinion and the coach's decision visibly apart, and that
 * pressing the button is what a learner's score depends on.
 */
class PanelBoardTest extends PanelTestCase
{
    // ------------------------------------------------------------- the board

    public function test_the_coach_reads_and_answers_the_board(): void
    {
        $thread = $this->threadFromALearner();

        $this->actingAs($this->coach)
            ->get(route('panel.forum.index', $this->group))
            ->assertOk()
            ->assertSee($thread->title);

        $this->actingAs($this->coach)
            ->post(route('panel.forum.reply', $thread), ['body' => 'چون سوم‌شخص است.'])
            ->assertSessionHas('status');

        $this->assertDatabaseHas('class_thread_replies', [
            'class_thread_id' => $thread->id,
            'author_id' => $this->coach->id,
            'is_coach_answer' => true,
        ]);
    }

    public function test_the_coach_takes_a_post_down_and_puts_it_back(): void
    {
        $thread = $this->threadFromALearner();

        $this->actingAs($this->coach)
            ->post(route('panel.forum.hide', $thread), ['reason' => 'خارج از موضوع'])
            ->assertSessionHas('status');

        $this->assertNotNull($thread->fresh()->hidden_at);

        $this->actingAs($this->coach)
            ->post(route('panel.forum.restore', $thread))
            ->assertSessionHas('status');

        $this->assertNull($thread->fresh()->hidden_at);
    }

    public function test_somebody_from_another_school_cannot_read_the_board(): void
    {
        $this->actingAs($this->outsider)
            ->get(route('panel.forum.index', $this->group))
            ->assertForbidden();
    }

    // ---------------------------------------------------------- the homework

    public function test_the_coach_sets_and_publishes_homework(): void
    {
        $this->actingAs($this->coach)
            ->post(route('panel.homework.store', $this->group), [
                'kind' => 'writing',
                'title' => 'یک پاراگراف دربارهٔ تعطیلات',
                'points' => 100,
                'allow_late' => '1',
            ])
            ->assertSessionHas('status');

        $assignment = Assignment::firstOrFail();
        $this->assertFalse($assignment->isPublished());

        $this->actingAs($this->coach)
            ->post(route('panel.homework.publish', $assignment))
            ->assertSessionHas('status');

        $this->assertTrue($assignment->fresh()->isPublished());
        $this->assertSame(2, AssignmentSubmission::where('assignment_id', $assignment->id)->count());
    }

    /**
     * The marking screen shows both numbers and says which is which.
     *
     * A coach who cannot tell the machine's suggestion from their own decision
     * is a coach who will eventually sign off on something they did not read.
     */
    public function test_the_marking_screen_keeps_the_two_marks_apart(): void
    {
        [$assignment, $submission] = $this->handedIn();

        $submission->forceFill([
            'ai_score' => 62.5,
            'ai_feedback' => ['kind' => 'writing', 'summary' => 'یک پاراگراف روشن با چند لغزش زمانی.'],
            'ai_model' => 'fake-json-model',
            'ai_marked_at' => now(),
            'status' => AssignmentSubmission::MARKED,
        ])->save();

        $this->actingAs($this->coach)
            ->get(route('panel.homework.submission', [$assignment, $submission]))
            ->assertOk()
            ->assertSee('نظر دستیار هوشمند')
            ->assertSee('نمرهٔ نهایی با شماست')
            ->assertSee('یک پاراگراف روشن با چند لغزش زمانی.')
            ->assertSee('62.5');
    }

    public function test_pressing_the_button_is_what_the_learner_sees(): void
    {
        [$assignment, $submission] = $this->handedIn();

        $this->actingAs($this->coach)
            ->post(route('panel.homework.mark', [$assignment, $submission]), [
                'score' => 78,
                'feedback' => 'خوب بود؛ به سوم‌شخص دقت کن.',
            ])
            ->assertSessionHas('status');

        $submission->refresh();

        $this->assertSame(AssignmentSubmission::RETURNED, $submission->status);
        $this->assertSame(78.0, (float) $submission->score);
        $this->assertSame($this->coach->id, $submission->marked_by);
    }

    public function test_a_learner_cannot_open_the_marking_screen(): void
    {
        [$assignment, $submission] = $this->handedIn();

        $this->actingAs($this->student)
            ->get(route('panel.homework.submission', [$assignment, $submission]))
            ->assertForbidden();
    }

    public function test_every_board_and_homework_page_renders(): void
    {
        $thread = $this->threadFromALearner();
        [$assignment, $submission] = $this->handedIn();

        foreach ([
            route('panel.forum.index', $this->group),
            route('panel.forum.show', $thread),
            route('panel.homework.index', $this->group),
            route('panel.homework.show', $assignment),
            route('panel.homework.submission', [$assignment, $submission]),
        ] as $page) {
            $this->actingAs($this->coach)->get($page)->assertOk();
        }
    }

    // ------------------------------------------------------------- helpers

    private function threadFromALearner(): ClassThread
    {
        return app(ForumService::class)->createThread(
            $this->group,
            $this->student,
            ['title' => 'چرا he don’t غلط است؟', 'body' => 'در تمرین دیروز اشتباه زدم.'],
        );
    }

    /** @return array{0: Assignment, 1: AssignmentSubmission} */
    private function handedIn(): array
    {
        $homework = app(HomeworkService::class);

        $assignment = $homework->create($this->group, $this->coach, [
            'kind' => Assignment::WRITING,
            'title' => 'یک پاراگراف',
            'points' => 100,
        ]);
        $homework->publish($assignment, $this->coach);

        $submission = $homework->submit($assignment, $this->student, [
            'body' => 'Last summer I went to Isfahan.',
        ]);

        return [$assignment, $submission];
    }
}
