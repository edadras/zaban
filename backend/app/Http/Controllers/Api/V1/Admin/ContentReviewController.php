<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\ContentReview;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Services\Content\ValidationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The generated-content review queue (spec 37).
 *
 * Nothing reaches a learner without passing through here. Auto-publish is
 * available but off by default and can never override a hard validation
 * failure - questionable material stays in the queue.
 */
class ContentReviewController extends ApiController
{
    public function __construct(private ValidationService $validator) {}

    public function queue(Request $request)
    {
        $status = $request->string('status', 'draft')->toString();

        $rows = ContentReview::query()
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->filled('type'), function ($q) use ($request) {
                $map = ['lesson' => Lesson::class, 'exercise' => Exercise::class];
                $q->where('reviewable_type', $map[$request->string('type')->toString()] ?? '');
            })
            ->orderBy('validation_score')
            ->orderByDesc('id')
            ->paginate(min(100, $request->integer('per_page', 25)));

        return $this->ok($rows->through(fn (ContentReview $r) => [
            'id' => $r->id,
            'type' => class_basename($r->reviewable_type),
            'reviewable_id' => $r->reviewable_id,
            'status' => $r->status,
            'validation_score' => $r->validation_score !== null ? (float) $r->validation_score : null,
            'auto_publishable' => (bool) $r->auto_publishable,
            'failed_checks' => collect($r->validation_results ?? [])
                ->filter(fn ($c) => ($c['passed'] ?? true) === false)
                ->map(fn ($c, $k) => ['check' => $k, 'reason' => $c['reason'] ?? null])
                ->values(),
        ]));
    }

    /** Full preview of the item under review. */
    public function show(Request $request, ContentReview $review)
    {
        $subject = $review->reviewable_type::find($review->reviewable_id);
        if (! $subject) {
            return $this->fail('missing_subject', 'The item under review no longer exists.', 404);
        }

        return $this->ok([
            'review' => [
                'id' => $review->id,
                'status' => $review->status,
                'validation_score' => $review->validation_score !== null ? (float) $review->validation_score : null,
                'validation_results' => $review->validation_results,
                'auto_publishable' => (bool) $review->auto_publishable,
                'reviewer_notes' => $review->reviewer_notes,
                'rejection_reason' => $review->rejection_reason,
            ],
            'subject' => $subject instanceof Exercise
                ? $this->exercisePreview($subject)
                : $this->lessonPreview($subject),
        ]);
    }

    /** Re-run the automated checks. */
    public function revalidate(Request $request, ContentReview $review)
    {
        $subject = $review->reviewable_type::find($review->reviewable_id);
        if (! $subject) {
            return $this->fail('missing_subject', 'The item under review no longer exists.', 404);
        }

        return $this->ok($this->validator->review($subject)->only([
            'validation_score', 'validation_results', 'auto_publishable',
        ]));
    }

    public function decide(Request $request, ContentReview $review)
    {
        $data = $request->validate([
            'decision' => ['required', 'in:approved,published,rejected,review'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $subject = $review->reviewable_type::find($review->reviewable_id);
        if (! $subject) {
            return $this->fail('missing_subject', 'The item under review no longer exists.', 404);
        }

        // Publishing is the one decision that reaches learners, so it is gated
        // on the automated checks as well as the reviewer's judgement.
        if ($data['decision'] === 'published') {
            $result = $subject instanceof Exercise
                ? $this->validator->validateExercise($subject)
                : $this->validator->validateLesson($subject);

            if ($result['hard_failure']) {
                return $this->fail(
                    'validation_blocked',
                    'This item fails a blocking validation check and cannot be published.',
                    422,
                    ['checks' => collect($result['checks'])->filter(fn ($c) => ! $c['passed'])->all()],
                );
            }
        }

        $before = ['status' => $review->status];

        DB::transaction(function () use ($review, $subject, $data, $request) {
            $review->update([
                'status' => $data['decision'],
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'reviewer_notes' => $data['notes'] ?? $review->reviewer_notes,
                'rejection_reason' => $data['decision'] === 'rejected' ? ($data['reason'] ?? null) : null,
            ]);

            $subject->update(['status' => $data['decision'] === 'review' ? 'review' : $data['decision']]);
        });

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'content.'.$data['decision'],
            'auditable_type' => $review->reviewable_type,
            'auditable_id' => $review->reviewable_id,
            'before' => $before,
            'after' => ['status' => $data['decision']],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
        ]);

        return $this->ok(['id' => $review->id, 'status' => $review->fresh()->status]);
    }

    /** Publish everything that passes the bar, when auto-publish is enabled. */
    public function autoPublish(Request $request)
    {
        if (! config('content.auto_publish_enabled')) {
            return $this->fail('auto_publish_disabled',
                'Auto-publish is disabled. Enable CONTENT_AUTO_PUBLISH to use it.', 422);
        }

        $limit = min(500, max(1, $request->integer('limit', 100)));
        $published = 0;

        ContentReview::where('status', 'draft')
            ->where('auto_publishable', true)
            ->where('validation_score', '>=', ValidationService::AUTO_PUBLISH_THRESHOLD)
            ->limit($limit)->get()
            ->each(function (ContentReview $r) use (&$published, $request) {
                $subject = $r->reviewable_type::find($r->reviewable_id);
                if (! $subject) {
                    return;
                }
                $r->update(['status' => 'published', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
                $subject->update(['status' => 'published']);
                $published++;
            });

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'content.auto_publish',
            'after' => ['published' => $published],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok(['published' => $published]);
    }

    /** Validate a batch that has never been checked. */
    public function validateBatch(Request $request)
    {
        $limit = min(2000, max(1, $request->integer('limit', 500)));
        $checked = 0;

        ContentReview::whereNull('validation_score')->limit($limit)->get()
            ->each(function (ContentReview $r) use (&$checked) {
                $subject = $r->reviewable_type::find($r->reviewable_id);
                if ($subject) {
                    $this->validator->review($subject);
                    $checked++;
                }
            });

        return $this->ok(['validated' => $checked]);
    }

    private function exercisePreview(Exercise $e): array
    {
        return [
            'kind' => 'exercise',
            'id' => $e->id,
            'template' => $e->template?->code,
            'stem' => $e->stem,
            'instructions' => $e->instructions,
            'difficulty' => (float) $e->difficulty,
            'generation_method' => $e->generation_method,
            'source' => ['document_id' => $e->source_document_id, 'page' => $e->source_page,
                         'reference' => $e->source_reference],
            // The reviewer needs to see the key; the learner never does.
            'options' => $e->options()->orderBy('position')->get()
                ->map(fn ($o) => ['text' => $o->text, 'is_correct' => (bool) $o->is_correct])->values(),
            'answers' => $e->answers()->get()->map(fn ($a) => ['value' => $a->value, 'mode' => $a->match_mode])->values(),
            'concepts' => DB::table('exercise_concepts')
                ->join('concepts', 'concepts.id', '=', 'exercise_concepts.concept_id')
                ->where('exercise_concepts.exercise_id', $e->id)->pluck('concepts.label'),
        ];
    }

    private function lessonPreview(Lesson $l): array
    {
        return [
            'kind' => 'lesson',
            'id' => $l->id,
            'title' => $l->title,
            'summary' => $l->summary,
            'generation_method' => $l->generation_method,
            'source' => ['document_id' => $l->source_document_id, 'page' => $l->source_page,
                         'section' => $l->source_section],
            'blocks' => $l->blocks()->orderBy('position')->get()
                ->map(fn ($b) => ['type' => $b->type, 'title' => $b->title, 'config' => $b->config])->values(),
            'concepts' => $l->concepts()->pluck('label'),
        ];
    }
}
