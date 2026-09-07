<?php

namespace App\Http\Controllers\Panel;

use App\Models\ClassGroup;
use App\Models\ClassThread;
use App\Models\ClassThreadReply;
use App\Services\Classroom\AttachmentService;
use App\Services\Classroom\ClassroomAiService;
use App\Services\Classroom\ForumService;
use App\Support\PanelAccess;
use Illuminate\Http\Request;

/** The class's board, as the coach reads and moderates it. */
class ForumController extends PanelController
{
    public function __construct(
        PanelAccess $access,
        private readonly ForumService $forum,
        private readonly ClassroomAiService $assistant,
    ) {
        parent::__construct($access);
    }

    public function index(Request $request, ClassGroup $group)
    {
        $this->allow($this->forum->canRead($group, $this->me()));

        $threads = ClassThread::with(['author:id,name'])
            ->withCount('replies')
            ->where('class_group_id', $group->id)
            ->when($request->boolean('unresolved'), fn ($q) => $q->where('status', ClassThread::OPEN))
            ->when($request->boolean('hidden'), fn ($q) => $q->whereNotNull('hidden_at'))
            ->orderByRaw('pinned_at IS NULL')
            ->orderByDesc('pinned_at')
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();

        return view('panel.forum.index', [
            'group' => $group,
            'threads' => $threads,
            'canModerate' => $this->forum->canModerate($group, $this->me()),
        ]);
    }

    public function show(ClassThread $thread)
    {
        $this->allow($this->forum->canRead($thread->group, $this->me()));

        $thread->load(['author:id,name', 'attachments.media']);

        return view('panel.forum.show', [
            'thread' => $thread,
            'group' => $thread->group,
            'replies' => $thread->replies()->with(['author:id,name', 'attachments.media'])->get(),
            'canModerate' => $this->forum->canModerate($thread->group, $this->me()),
        ]);
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

        return $this->attempt(
            fn () => $this->forum->createThread($group, $this->me(), $data, $request->file('files', [])),
            route('panel.forum.index', $group),
            'روی تابلوی کلاس گذاشته شد.',
        );
    }

    public function reply(Request $request, ClassThread $thread)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'files' => ['nullable', 'array', 'max:6'],
            'files.*' => ['file', 'max:'.AttachmentService::MAX_KILOBYTES],
        ]);

        return $this->attempt(
            fn () => $this->forum->reply($thread, $this->me(), $data['body'], $request->file('files', [])),
            route('panel.forum.show', $thread),
            'پاسخ شما ثبت شد.',
        );
    }

    public function accept(ClassThread $thread, ClassThreadReply $reply)
    {
        return $this->attempt(
            fn () => $this->forum->accept($thread, $reply, $this->me()),
            route('panel.forum.show', $thread),
            'به‌عنوان پاسخ درست علامت خورد.',
        );
    }

    public function endorse(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        $this->allow($this->forum->canModerate($thread->group, $this->me()));

        return $this->attempt(
            fn () => $this->assistant->endorse($reply, $this->me(), $request->boolean('endorsed', true)),
            route('panel.forum.show', $thread),
            'پاسخ دستیار تأیید شد.',
        );
    }

    public function hide(Request $request, ClassThread $thread)
    {
        return $this->attempt(
            fn () => $this->forum->hide($thread, $this->me(), $request->input('reason')),
            route('panel.forum.index', $thread->group),
            'از دید کلاس برداشته شد.',
        );
    }

    public function hideReply(Request $request, ClassThread $thread, ClassThreadReply $reply)
    {
        return $this->attempt(
            fn () => $this->forum->hide($reply, $this->me(), $request->input('reason')),
            route('panel.forum.show', $thread),
            'پاسخ از دید کلاس برداشته شد.',
        );
    }

    public function restore(ClassThread $thread)
    {
        return $this->attempt(
            fn () => $this->forum->restore($thread, $this->me()),
            route('panel.forum.show', $thread),
            'دوباره برای کلاس دیده می‌شود.',
        );
    }

    public function pin(Request $request, ClassThread $thread)
    {
        return $this->attempt(
            fn () => $this->forum->pin($thread, $this->me(), ! $thread->pinned_at),
            route('panel.forum.show', $thread),
            'انجام شد.',
        );
    }

    public function lock(Request $request, ClassThread $thread)
    {
        return $this->attempt(
            fn () => $this->forum->lock($thread, $this->me(), ! $thread->isLocked()),
            route('panel.forum.show', $thread),
            'انجام شد.',
        );
    }
}
