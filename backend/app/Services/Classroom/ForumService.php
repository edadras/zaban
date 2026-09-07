<?php

namespace App\Services\Classroom;

use App\Events\Classroom\ClassBoardEvent;
use App\Jobs\AnswerClassQuestion;
use App\Models\ClassGroup;
use App\Models\ClassThread;
use App\Models\ClassThreadRead;
use App\Models\ClassThreadReply;
use App\Models\ClassThreadVote;
use App\Models\User;
use App\Notifications\ClassBoardActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * The class's board.
 *
 * Who may read it and who may moderate it is the same question the rest of the
 * module answers - are you on this roll, do you teach this class - so it is
 * asked here in the same terms rather than invented again.
 *
 * Two decisions worth naming. Moderation hides rather than deletes: a post
 * taken down is still there for the coach and the school, because "it was
 * removed" and "it never existed" are different facts and a school needs the
 * first. And a learner may edit or withdraw their own post but never somebody
 * else's, while a coach may hide anything and edit nothing - a board where the
 * teacher can rewrite what a learner said is not a board anyone will use.
 */
class ForumService
{
    /** Long enough to fix a typo, short enough that the record stands. */
    private const EDIT_WINDOW_MINUTES = 30;

    public function __construct(
        private readonly SchoolService $schools,
        private readonly AttachmentService $attachments,
        private readonly ClassroomAiService $assistant,
    ) {}

    // ---------------------------------------------------------- who may what

    public function canRead(ClassGroup $group, User $user): bool
    {
        return $this->canModerate($group, $user)
            || $group->students()->whereKey($user->id)->exists();
    }

    public function canModerate(ClassGroup $group, User $user): bool
    {
        return $group->coach_id === $user->id
            || $this->schools->isManager($group->school, $user);
    }

    public function assertCanRead(ClassGroup $group, User $user): void
    {
        if (! $this->canRead($group, $user)) {
            throw new ClassroomException('This board belongs to a class you are not in.', 403);
        }
    }

    // ------------------------------------------------------------- threads

    /**
     * @param  array{kind?:string,title:string,body?:?string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function createThread(ClassGroup $group, User $author, array $data, array $files = []): ClassThread
    {
        $this->assertCanRead($group, $author);

        $kind = $data['kind'] ?? ClassThread::QUESTION;

        // An announcement is the coach talking to the class. A learner posting
        // one would be a learner speaking for the school.
        if ($kind === ClassThread::ANNOUNCEMENT && ! $this->canModerate($group, $author)) {
            throw new ClassroomException('Only the coach can post an announcement.', 403);
        }

        $thread = DB::transaction(function () use ($group, $author, $data, $kind) {
            return ClassThread::create([
                'class_group_id' => $group->id,
                'author_id' => $author->id,
                'kind' => $kind,
                'title' => $data['title'],
                'body' => $data['body'] ?? null,
                'status' => ClassThread::OPEN,
                'last_activity_at' => now(),
            ]);
        });

        $this->attachments->attachMany($thread, $author, $files);
        $this->markRead($thread, $author);

        $this->notifyClass($thread, $author, 'class.board.thread');

        /*
         * Give the assistant a go at it, a few minutes from now.
         *
         * Late on purpose: a board where the machine always answers first is a
         * board where classmates stop bothering, and the job does nothing at
         * all if somebody got there first.
         */
        if ($kind === ClassThread::QUESTION && $this->assistant->boardAssistantEnabled()) {
            AnswerClassQuestion::dispatch($thread->id)
                ->delay(now()->addSeconds((int) config('classroom.ai.board_assistant_delay_seconds', 180)));
        }

        event(new ClassBoardEvent($group->id, ClassBoardEvent::THREAD_POSTED, [
            'thread_id' => $thread->id,
        ]));

        return $thread->load('attachments.media');
    }

    /** A learner tidying up their own question, inside a short window. */
    public function editThread(ClassThread $thread, User $user, array $data): ClassThread
    {
        $this->assertAuthorMayEdit($thread->author_id, $thread->created_at, $user);

        $thread->update(array_filter([
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
        ], fn ($v) => $v !== null));

        return $thread;
    }

    public function reply(ClassThread $thread, User $author, string $body, array $files = []): ClassThreadReply
    {
        $group = $thread->group;
        $this->assertCanRead($group, $author);

        if ($thread->isLocked() && ! $this->canModerate($group, $author)) {
            throw new ClassroomException('This thread is closed.');
        }
        if ($thread->isHidden()) {
            throw new ClassroomException('This thread has been taken down.', 403);
        }

        $reply = DB::transaction(function () use ($thread, $author, $body, $group) {
            $reply = ClassThreadReply::create([
                'class_thread_id' => $thread->id,
                'author_id' => $author->id,
                'body' => $body,
                'is_coach_answer' => $this->canModerate($group, $author),
            ]);

            $thread->increment('reply_count');
            $thread->forceFill(['last_activity_at' => now()])->save();

            return $reply;
        });

        $this->attachments->attachMany($reply, $author, $files);
        $this->markRead($thread, $author);

        // The person who asked, and nobody else: a board that notifies the
        // whole class on every reply is a board people mute.
        if ($thread->author_id !== $author->id) {
            $thread->author?->notify(new ClassBoardActivity(
                $thread,
                'class.board.reply',
                ['reply_id' => $reply->id, 'by' => $author->name],
            ));
        }

        event(new ClassBoardEvent($group->id, ClassBoardEvent::REPLY_POSTED, [
            'thread_id' => $thread->id,
            'reply_id' => $reply->id,
        ]));

        return $reply->load('attachments.media');
    }

    /**
     * "This is the answer."
     *
     * The person who asked, or the coach. Not the person who wrote it - a board
     * where you can mark your own answer correct is a scoreboard.
     */
    public function accept(ClassThread $thread, ClassThreadReply $reply, User $user): ClassThread
    {
        $this->assertBelongs($thread, $reply);

        if ($thread->author_id !== $user->id && ! $this->canModerate($thread->group, $user)) {
            throw new ClassroomException('Only the person who asked, or the coach, can accept an answer.', 403);
        }

        $thread->update([
            'accepted_reply_id' => $reply->id,
            'status' => ClassThread::RESOLVED,
            'last_activity_at' => now(),
        ]);

        event(new ClassBoardEvent($thread->class_group_id, ClassBoardEvent::REPLY_ACCEPTED, [
            'thread_id' => $thread->id,
            'reply_id' => $reply->id,
        ]));

        return $thread;
    }

    /** One person, one vote, and pressing it again takes it back. */
    public function toggleVote(ClassThreadReply $reply, User $user): bool
    {
        $this->assertCanRead($reply->thread->group, $user);

        $existing = ClassThreadVote::where('class_thread_reply_id', $reply->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing !== null) {
            $existing->delete();
            $reply->decrement('helpful_count');

            return false;
        }

        ClassThreadVote::create([
            'class_thread_reply_id' => $reply->id,
            'user_id' => $user->id,
        ]);
        $reply->increment('helpful_count');

        return true;
    }

    // ---------------------------------------------------------- moderation

    public function hide(ClassThread|ClassThreadReply $post, User $by, ?string $reason = null): void
    {
        $group = $post instanceof ClassThread ? $post->group : $post->thread->group;

        if (! $this->canModerate($group, $by)) {
            throw new ClassroomException('Only the coach or the school can take a post down.', 403);
        }

        $post->forceFill([
            'hidden_at' => now(),
            'hidden_by' => $by->id,
            'hidden_reason' => $reason,
        ])->save();
    }

    public function restore(ClassThread|ClassThreadReply $post, User $by): void
    {
        $group = $post instanceof ClassThread ? $post->group : $post->thread->group;

        if (! $this->canModerate($group, $by)) {
            throw new ClassroomException('Only the coach or the school can put a post back.', 403);
        }

        $post->forceFill(['hidden_at' => null, 'hidden_by' => null, 'hidden_reason' => null])->save();
    }

    /** A learner withdrawing their own post; the coach hides instead. */
    public function withdraw(ClassThread|ClassThreadReply $post, User $user): void
    {
        if ($post->author_id !== $user->id) {
            throw new ClassroomException('That is not yours to remove.', 403);
        }

        if ($post instanceof ClassThreadReply) {
            $post->thread->decrement('reply_count');
        }

        $post->delete();
    }

    public function pin(ClassThread $thread, User $by, bool $pinned): ClassThread
    {
        if (! $this->canModerate($thread->group, $by)) {
            throw new ClassroomException('Only the coach can pin a thread.', 403);
        }

        $thread->update(['pinned_at' => $pinned ? now() : null]);

        return $thread;
    }

    public function lock(ClassThread $thread, User $by, bool $locked): ClassThread
    {
        if (! $this->canModerate($thread->group, $by)) {
            throw new ClassroomException('Only the coach can close a thread.', 403);
        }

        $thread->update([
            'locked_at' => $locked ? now() : null,
            'status' => $locked ? ClassThread::CLOSED : ClassThread::OPEN,
        ]);

        return $thread;
    }

    // -------------------------------------------------------------- reading

    public function markRead(ClassThread $thread, User $user): void
    {
        ClassThreadRead::updateOrCreate(
            ['class_thread_id' => $thread->id, 'user_id' => $user->id],
            ['read_at' => now()],
        );
    }

    /** @return array<int, bool> thread id => is there something new */
    public function unreadMap(ClassGroup $group, User $user, array $threadIds): array
    {
        $reads = ClassThreadRead::where('user_id', $user->id)
            ->whereIn('class_thread_id', $threadIds)
            ->pluck('read_at', 'class_thread_id');

        return ClassThread::whereIn('id', $threadIds)
            ->pluck('last_activity_at', 'id')
            ->map(function ($activity, $id) use ($reads) {
                $read = $reads[$id] ?? null;

                return $read === null || ($activity !== null && $activity->greaterThan($read));
            })
            ->all();
    }

    // ------------------------------------------------------------- private

    private function assertBelongs(ClassThread $thread, ClassThreadReply $reply): void
    {
        if ($reply->class_thread_id !== $thread->id) {
            throw new ClassroomException('That reply belongs to another thread.', 404);
        }
    }

    private function assertAuthorMayEdit(int $authorId, $createdAt, User $user): void
    {
        if ($authorId !== $user->id) {
            throw new ClassroomException('You can only edit your own post.', 403);
        }
        if ($createdAt !== null && $createdAt->addMinutes(self::EDIT_WINDOW_MINUTES)->isPast()) {
            throw new ClassroomException('This post is too old to edit. Reply instead.');
        }
    }

    /**
     * Tell the class there is a new thread.
     *
     * Everyone on the roll except whoever wrote it, and honouring the learner's
     * own notification setting - the board is not urgent the way a class
     * starting is.
     */
    private function notifyClass(ClassThread $thread, User $author, string $kind): void
    {
        $recipients = $thread->group?->students()->get() ?? collect();

        foreach ($recipients as $student) {
            if ($student->id === $author->id) {
                continue;
            }
            if (! ($student->settings?->reminder_enabled ?? true)) {
                continue;
            }

            $student->notify(new ClassBoardActivity($thread, $kind, ['by' => $author->name]));
        }

        // The coach hears about every question, whether or not they are on the
        // roll - they are the person expected to answer it.
        if ($thread->group?->coach_id !== null && $thread->group->coach_id !== $author->id) {
            $thread->group->coach?->notify(new ClassBoardActivity($thread, $kind, ['by' => $author->name]));
        }
    }
}
