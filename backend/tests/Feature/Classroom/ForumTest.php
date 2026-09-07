<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassThread;
use App\Models\ClassThreadReply;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The class's board.
 *
 * Most of what matters here is who cannot see what. A board scoped to a class
 * is only worth having if it is actually scoped: a learner in another class of
 * the same school must not be able to read a question, and a post taken down
 * must disappear for the class while staying visible to the coach.
 */
class ForumTest extends ClassroomTestCase
{
    public function test_a_learner_asks_the_class_a_question(): void
    {
        $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", [
                'title' => 'چرا he don’t غلط است؟',
                'body' => 'در تمرین دیروز این را اشتباه زدم.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'question')
            ->assertJsonPath('data.status', 'open');

        $this->assertDatabaseHas('class_threads', [
            'class_group_id' => $this->group->id,
            'author_id' => $this->student->id,
        ]);
    }

    /** The whole point of scoping it to a class. */
    public function test_somebody_from_outside_the_class_sees_nothing(): void
    {
        $thread = $this->makeThread();

        $this->actingAs($this->outsider)
            ->getJson("/api/v1/classes/{$this->group->id}/threads")
            ->assertStatus(403);

        $this->actingAs($this->outsider)
            ->getJson("/api/v1/threads/{$thread->id}")
            ->assertStatus(403);

        $this->actingAs($this->outsider)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'سلام'])
            ->assertStatus(403);
    }

    public function test_classmates_and_the_coach_can_answer(): void
    {
        $thread = $this->makeThread();

        $this->actingAs($this->otherStudent)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'چون سوم‌شخص است.'])
            ->assertCreated()
            ->assertJsonPath('data.is_coach_answer', false);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'درست است؛ does + not.'])
            ->assertCreated()
            // Marked apart in the interface: an answer from the person who
            // teaches the class is not just another opinion.
            ->assertJsonPath('data.is_coach_answer', true);

        $this->assertSame(2, $thread->fresh()->reply_count);
    }

    public function test_a_question_carries_pictures_and_video(): void
    {
        Storage::fake(config('filesystems.default'));

        $response = $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", [
                'title' => 'این جمله را نمی‌فهمم',
                'body' => 'عکس صفحهٔ کتاب را گذاشتم.',
                'files' => [
                    UploadedFile::fake()->image('page.jpg'),
                    UploadedFile::fake()->create('clip.mp4', 64, 'video/mp4'),
                ],
            ])
            ->assertCreated();

        $kinds = collect($response->json('data.attachments'))->pluck('kind')->all();

        $this->assertEqualsCanonicalizing(['image', 'video'], $kinds);
    }

    public function test_the_person_who_asked_marks_the_answer(): void
    {
        $thread = $this->makeThread();
        $reply = $this->makeReply($thread, $this->otherStudent);

        $this->actingAs($this->student)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.accepted_reply_id', $reply->id);
    }

    /** A board where you can mark your own answer correct is a scoreboard. */
    public function test_nobody_accepts_their_own_answer(): void
    {
        $thread = $this->makeThread();
        $reply = $this->makeReply($thread, $this->otherStudent);

        $this->actingAs($this->otherStudent)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/accept")
            ->assertStatus(403);
    }

    public function test_the_coach_can_accept_on_the_askers_behalf(): void
    {
        $thread = $this->makeThread();
        $reply = $this->makeReply($thread, $this->otherStudent);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/replies/{$reply->id}/accept")
            ->assertOk();
    }

    /** One person, one vote, and pressing it again takes it back. */
    public function test_helpful_is_one_vote_per_person(): void
    {
        $thread = $this->makeThread();
        $reply = $this->makeReply($thread, $this->otherStudent);
        $url = "/api/v1/threads/{$thread->id}/replies/{$reply->id}/helpful";

        $this->actingAs($this->student)->postJson($url)->assertOk()
            ->assertJsonPath('data.helpful_count', 1);
        $this->actingAs($this->student)->postJson($url)->assertOk()
            ->assertJsonPath('data.helpful_count', 0);

        $this->actingAs($this->student)->postJson($url);
        $this->actingAs($this->otherStudent)->postJson($url)->assertOk()
            ->assertJsonPath('data.helpful_count', 2);
    }

    // ---------------------------------------------------------- moderation

    /**
     * Moderation hides rather than deletes.
     *
     * "It was removed" and "it never existed" are different facts, and a
     * school needs the first one.
     */
    public function test_a_hidden_post_disappears_for_the_class_and_not_for_the_coach(): void
    {
        $thread = $this->makeThread();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/hide", ['reason' => 'زبان نامناسب'])
            ->assertOk();

        $this->actingAs($this->otherStudent)
            ->getJson("/api/v1/classes/{$this->group->id}/threads")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($this->otherStudent)
            ->getJson("/api/v1/threads/{$thread->id}")
            ->assertStatus(403);

        $coachView = $this->actingAs($this->coach)
            ->getJson("/api/v1/classes/{$this->group->id}/threads")->assertOk();

        $this->assertCount(1, $coachView->json('data'));
        $this->assertSame('زبان نامناسب', $coachView->json('data.0.hidden_reason'));
    }

    public function test_a_learner_cannot_moderate(): void
    {
        $thread = $this->makeThread();

        foreach (['hide', 'pin', 'lock'] as $action) {
            $this->actingAs($this->otherStudent)
                ->postJson("/api/v1/threads/{$thread->id}/{$action}")
                ->assertStatus(403);
        }
    }

    /** A coach may take a post down; they may not rewrite what a learner said. */
    public function test_the_coach_cannot_edit_a_learners_words(): void
    {
        $thread = $this->makeThread();

        $this->actingAs($this->coach)
            ->patchJson("/api/v1/threads/{$thread->id}", ['body' => 'چیز دیگری'])
            ->assertStatus(403);

        $this->assertNotSame('چیز دیگری', $thread->fresh()->body);
    }

    public function test_a_learner_edits_their_own_post_briefly(): void
    {
        $thread = $this->makeThread();

        $this->actingAs($this->student)
            ->patchJson("/api/v1/threads/{$thread->id}", ['body' => 'اصلاح شد'])
            ->assertOk();

        $this->travel(31)->minutes();

        $this->actingAs($this->student)
            ->patchJson("/api/v1/threads/{$thread->id}", ['body' => 'باز هم'])
            ->assertStatus(422);
    }

    public function test_a_closed_thread_takes_no_more_answers(): void
    {
        $thread = $this->makeThread();

        $this->actingAs($this->coach)->postJson("/api/v1/threads/{$thread->id}/lock");

        $this->actingAs($this->otherStudent)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'یک چیز دیگر'])
            ->assertStatus(422);

        // The coach still gets the last word.
        $this->actingAs($this->coach)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'بسته شد.'])
            ->assertCreated();
    }

    /** An announcement is the school talking; a learner posting one is not. */
    public function test_only_the_coach_announces(): void
    {
        $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", [
                'kind' => 'announcement',
                'title' => 'کلاس فردا تعطیل است',
            ])
            ->assertStatus(403);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", [
                'kind' => 'announcement',
                'title' => 'کلاس فردا تعطیل است',
            ])
            ->assertCreated();
    }

    // ------------------------------------------------------------ the bell

    public function test_a_new_question_reaches_the_class_and_the_coach(): void
    {
        $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", ['title' => 'یک پرسش']);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->otherStudent->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->coach->id]);
        // Not the person who wrote it.
        $this->assertDatabaseMissing('notifications', ['notifiable_id' => $this->student->id]);
    }

    /** A reply goes to whoever asked, and to nobody else. */
    public function test_a_reply_tells_only_the_asker(): void
    {
        $thread = $this->makeThread();
        DB::table('notifications')->delete();

        $this->actingAs($this->otherStudent)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'اینطوری است']);

        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->student->id]);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_a_thread_is_unread_until_it_is_opened(): void
    {
        $thread = $this->makeThread();

        $list = $this->actingAs($this->otherStudent)
            ->getJson("/api/v1/classes/{$this->group->id}/threads")->assertOk();
        $this->assertTrue($list->json('data.0.is_unread'));

        $this->actingAs($this->otherStudent)->getJson("/api/v1/threads/{$thread->id}");

        $after = $this->actingAs($this->otherStudent)
            ->getJson("/api/v1/classes/{$this->group->id}/threads")->assertOk();
        $this->assertFalse($after->json('data.0.is_unread'));
    }

    // ------------------------------------------------------------- helpers

    private function makeThread(): ClassThread
    {
        $response = $this->actingAs($this->student)
            ->postJson("/api/v1/classes/{$this->group->id}/threads", [
                'title' => 'چرا he don’t غلط است؟',
                'body' => 'در تمرین دیروز این را اشتباه زدم.',
            ])->assertCreated();

        return ClassThread::findOrFail($response->json('data.id'));
    }

    private function makeReply(ClassThread $thread, User $author): ClassThreadReply
    {
        $response = $this->actingAs($author)
            ->postJson("/api/v1/threads/{$thread->id}/replies", ['body' => 'چون سوم‌شخص است.'])
            ->assertCreated();

        return ClassThreadReply::findOrFail($response->json('data.id'));
    }
}
