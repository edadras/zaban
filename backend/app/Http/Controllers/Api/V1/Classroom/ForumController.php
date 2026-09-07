<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ClassAttachment;
use App\Models\ClassGroup;
use App\Models\ClassThread;
use App\Models\ClassThreadReply;
use App\Models\ClassThreadVote;
use App\Services\Classroom\AttachmentService;
use App\Services\Classroom\ClassroomAiService;
use App\Services\Classroom\ClassroomException;
use App\Services\Classroom\ForumService;
use Illuminate\Http\Request;

/**
 * The class's board.
 *
 * A learner reads and writes here; the coach does that and moderates. Every
 * route asks the same first question - are you on this roll - and the service
 * answers it, so there is no route on which a class's questions leak to a class
 * that is not theirs.
 */
class ForumController extends ApiController
{
    public function __construct(
        private readonly ForumService $forum,
        private readonly AttachmentService $attachments,
        private readonly ClassroomAiService $assistant,
    ) {}

    public function index(Request $request, ClassGroup $group)
    {
        $this->forum->assertCanRead($group, $request->user());

        $moderator = $this->forum->canModerate($group, $request->user());

        $threads = ClassThread::with(['author:id,name', 'attachments.media'])
            ->where('class_group_id', $group->id)
            // A post that was taken down stays visible to the coach and the
            // school, and to nobody else.
            ->when(! $moderator, fn ($q) => $q->whereNull('hidden_at'))
            ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
            ->when($request->boolean('unresolved'), fn ($q) => $q->where('status', ClassThread::OPEN))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('body', 'like', $term));
            })
            ->orderByRaw('pinned_at IS NULL')
            ->orderByDesc('pinned_at')
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();

        $unread = $this->forum->unreadMap($group, $request->user(), $threads->pluck('id')->all());

        return $this->ok(
            $threads->map(fn (ClassThread $t) => $this->presentThread($t) + [
                'is_unread' => $unread[$t->id] ?? false,
            ]),
            ['can_moderate' => $moderator, 'class' => $group->title],
        );
    }

    public function store(Request $request, ClassGroup $group)
    {
        $data = $request->validate([
            'kind' => ['nullable', 'string', 'in:'.implode(',', ClassThread::KINDS)],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:6'],
            'files.*' => ['file', 'max:'.AttachmentService::MAX_KILOBYTES],
        ]);

        $thread = $this->forum->createThread(
            $group,
            $request->user(),
            $data,
            $request->file('files', []),
        );

        return $this->created($this->presentThread($thread));
    }

    public function show(Request $request, ClassThread $thread)
    {
        $this->forum->assertCanRead($thread->group, $request->user());

        $user = $request->user();
        $moderator = $this->forum->canModerate($thread->group, $user);

        if ($thread->isHidden() && ! $moderator) {
            throw new ClassroomException('This thread has been taken down.', 403);
        }

        $thread->loadMissing(['author:id,name', 'attachments.media']);
        $thread->increment('view_count');

        $replies = $thread->replies()
            ->with(['author:id,name', 'attachments.media'])
            ->when(! $moderator, fn ($q) => $q->whereNull('hidden_at'))
            ->get();

        $voted = ClassThreadVote::where('user_id', $user->id)
            ->whereIn('class_thread_reply_id', $replies->pluck('id'))
            ->pluck('class_thread_reply_id')
            ->all();

        $this->forum->markRead($thread, $user);

        return $this->ok([
            'thread' => $this->presentThread($thread),
            'replies' => $replies->map(fn (ClassThreadReply $r) => $this->presentReply($r, $voted)),
            'can_moderate' => $moderator,
            'can_reply' => ! $thread->isLocked() || $moderator,
            'is_author' => $thread->author_id === $user->id,
        ]);
    }

    public function update(Request $request, ClassThread $thread)
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->ok($this->presentThread(
            $this->forum->editThread($thread, $request->user(), $data)
        ));
    }

    public function destroy(Request $request, ClassThread $thread)
    {
        $this->forum->withdraw($thread, $request->user());

        return $this->ok(['withdrawn' => true]);
    }

    // -------------------------------------------------------------- replies

    public function reply(Request $request, ClassThread $thread)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:6'],
            'files.*' => ['file', 'max:'.AttachmentService::MAX_KILOBYTES],
        ]);

        $reply = $this->forum->reply(
            $thread,
            $request->user(),
            $data['body'],
            $request->file('files', []),
        );

        return $this->created($this->presentReply($reply, []));
    }

    public function removeReply(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        $this->forum->withdraw($reply, $request->user());

        return $this->ok(['withdrawn' => true]);
    }

    public function accept(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        return $this->ok($this->presentThread(
            $this->forum->accept($thread, $reply, $request->user())
        ));
    }

    public function vote(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        $voted = $this->forum->toggleVote($reply, $request->user());

        return $this->ok(['voted' => $voted, 'helpful_count' => $reply->fresh()->helpful_count]);
    }

    // ----------------------------------------------------------- moderation

    public function hide(Request $request, ClassThread $thread)
    {
        $this->forum->hide($thread, $request->user(), $request->string('reason')->value() ?: null);

        return $this->ok(['hidden' => true]);
    }

    public function hideReply(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        $this->forum->hide($reply, $request->user(), $request->string('reason')->value() ?: null);

        return $this->ok(['hidden' => true]);
    }

    public function restore(Request $request, ClassThread $thread)
    {
        $this->forum->restore($thread, $request->user());

        return $this->ok(['restored' => true]);
    }

    /**
     * The coach putting their name to the assistant's answer.
     *
     * The only thing that turns a labelled draft into an answer the class can
     * rely on. Taking it back is the same route with `endorsed=false`.
     */
    public function endorse(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        if (! $this->forum->canModerate($thread->group, $request->user())) {
            throw new ClassroomException('Only the coach can endorse an answer.', 403);
        }

        return $this->ok($this->presentReply(
            $this->assistant->endorse($reply, $request->user(), $request->boolean('endorsed', true)),
            [],
        ));
    }

    public function pin(Request $request, ClassThread $thread)
    {
        return $this->ok($this->presentThread(
            $this->forum->pin($thread, $request->user(), $request->boolean('pinned', true))
        ));
    }

    public function lock(Request $request, ClassThread $thread)
    {
        return $this->ok($this->presentThread(
            $this->forum->lock($thread, $request->user(), $request->boolean('locked', true))
        ));
    }

    // ------------------------------------------------------------ presenting

    private function presentThread(ClassThread $thread): array
    {
        return [
            'id' => $thread->id,
            'class_group_id' => $thread->class_group_id,
            'kind' => $thread->kind,
            'title' => $thread->title,
            'body' => $thread->body,
            'status' => $thread->status,
            'author' => $thread->author?->name,
            'author_id' => $thread->author_id,
            'accepted_reply_id' => $thread->accepted_reply_id,
            'is_pinned' => $thread->pinned_at !== null,
            'is_locked' => $thread->isLocked(),
            'is_hidden' => $thread->isHidden(),
            'hidden_reason' => $thread->hidden_reason,
            'reply_count' => $thread->reply_count,
            'view_count' => $thread->view_count,
            'attachments' => $thread->attachments->map(
                fn (ClassAttachment $a) => $this->attachments->present($a)
            ),
            'created_at' => $thread->created_at?->toIso8601String(),
            'last_activity_at' => $thread->last_activity_at?->toIso8601String(),
        ];
    }

    /** @param  list<int>  $voted  replies this person has already found helpful */
    private function presentReply(ClassThreadReply $reply, array $voted): array
    {
        return [
            'id' => $reply->id,
            'author' => $reply->author?->name,
            'author_id' => $reply->author_id,
            'body' => $reply->body,
            'is_coach_answer' => $reply->is_coach_answer,
            'is_ai_answer' => $reply->is_ai_answer,
            'ai_endorsed' => $reply->ai_endorsed,
            'helpful_count' => $reply->helpful_count,
            'i_found_it_helpful' => in_array($reply->id, $voted, true),
            'is_hidden' => $reply->isHidden(),
            'hidden_reason' => $reply->hidden_reason,
            'attachments' => $reply->attachments->map(
                fn (ClassAttachment $a) => $this->attachments->present($a)
            ),
            'created_at' => $reply->created_at?->toIso8601String(),
        ];
    }
}
