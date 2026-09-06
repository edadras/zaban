<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\SourceDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What is in the curriculum, and what a learner is allowed to see of it.
 *
 * Every lesson imports as a draft, which is correct - the pipeline reads
 * scanned pages and nothing it produces should reach a learner unseen. But
 * "draft" was also invisible: there was no way to look at a book, see how much
 * of it carries artwork, audio and something to do, and then let it out.
 *
 * That is this controller. It reports coverage per book and per lesson from the
 * same joins `content:readiness` uses, and it publishes - one lesson, or every
 * lesson in a book that clears the bar.
 *
 * The bar is deliberately low and deliberately real: a lesson may be published
 * when it teaches an active concept and holds at least one block that is not
 * just the printed page. Anything less is a page to read with nothing to do,
 * and a learner who lands on it has been sent nowhere.
 */
class CurriculumController extends ApiController
{
    /** Block types that are the page itself rather than something to do. */
    private const PASSIVE_BLOCKS = ['source_text', 'image_scene'];

    /** Every book, with how much of it is ready and how much is published. */
    public function books()
    {
        $documents = SourceDocument::orderBy('id')->get(['id', 'title', 'status']);

        $rows = DB::table('lessons')
            ->leftJoin('lesson_concept', 'lesson_concept.lesson_id', '=', 'lessons.id')
            ->leftJoin('concepts', function ($j) {
                $j->on('concepts.id', '=', 'lesson_concept.concept_id')
                    ->where('concepts.is_active', '=', true);
            })
            ->whereNull('lessons.deleted_at')
            ->select('lessons.source_document_id', 'lessons.id', 'lessons.status')
            ->selectRaw('MAX(concepts.id) IS NOT NULL AS teaches')
            ->groupBy('lessons.source_document_id', 'lessons.id', 'lessons.status')
            ->get()
            ->groupBy('source_document_id');

        $interactive = $this->lessonIdsWithAnActivity();
        $withArtwork = $this->lessonIdsWithABlockOfType('image_scene');
        $withAudio = DB::table('audio_mappings')
            ->where('mappable_type', Lesson::class)
            ->distinct()->pluck('mappable_id')->flip();
        $askable = $this->lessonIdsWithARecognitionItem();

        return $this->ok($documents->map(function (SourceDocument $doc) use (
            $rows, $interactive, $withArtwork, $withAudio, $askable,
        ) {
            $lessons = $rows->get($doc->id, collect());
            $teaching = $lessons->where('teaches', true);

            return [
                'id' => $doc->id,
                'title' => $doc->title,
                'lessons' => $lessons->count(),
                'teaching' => $teaching->count(),
                'published' => $lessons->where('status', 'published')->count(),
                'ready' => $teaching->filter(fn ($l) => $interactive->has($l->id))->count(),
                'coverage' => [
                    'activity' => $teaching->filter(fn ($l) => $interactive->has($l->id))->count(),
                    'recognition' => $teaching->filter(fn ($l) => $askable->has($l->id))->count(),
                    'audio' => $lessons->filter(fn ($l) => $withAudio->has($l->id))->count(),
                    'artwork' => $lessons->filter(fn ($l) => $withArtwork->has($l->id))->count(),
                ],
            ];
        })->values());
    }

    /** The lessons of one book, each with what it does and does not carry. */
    public function lessons(Request $request, SourceDocument $document)
    {
        $lessons = Lesson::where('source_document_id', $document->id)
            ->with('unit:id,title,position')
            ->orderBy('unit_id')->orderBy('position')
            ->paginate(min(200, $request->integer('per_page', 50)));

        $ids = collect($lessons->items())->pluck('id');
        $interactive = $this->lessonIdsWithAnActivity($ids);
        $withArtwork = $this->lessonIdsWithABlockOfType('image_scene', $ids);
        $askable = $this->lessonIdsWithARecognitionItem($ids);
        $withAudio = DB::table('audio_mappings')
            ->where('mappable_type', Lesson::class)
            ->whereIn('mappable_id', $ids)
            ->distinct()->pluck('mappable_id')->flip();
        $teaches = DB::table('lesson_concept')
            ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
            ->where('concepts.is_active', true)
            ->whereIn('lesson_concept.lesson_id', $ids)
            ->distinct()->pluck('lesson_concept.lesson_id')->flip();

        return $this->ok($lessons->through(fn (Lesson $lesson) => [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'unit' => $lesson->unit?->title,
            'unit_number' => $lesson->unit?->position,
            'status' => $lesson->status,
            'teaches' => $teaches->has($lesson->id),
            'has_activity' => $interactive->has($lesson->id),
            'has_recognition_item' => $askable->has($lesson->id),
            'has_audio' => $withAudio->has($lesson->id),
            'has_artwork' => $withArtwork->has($lesson->id),
            'publishable' => $teaches->has($lesson->id) && $interactive->has($lesson->id),
        ]));
    }

    /** Publish or withdraw one lesson. */
    public function setLessonStatus(Request $request, Lesson $lesson)
    {
        $data = $request->validate(['status' => 'required|in:draft,published']);

        if ($data['status'] === 'published' && ! $this->isPublishable($lesson->id)) {
            return $this->fail(
                'lesson_not_ready',
                'This lesson teaches nothing active, or holds no block a learner can act on.',
                422,
            );
        }

        $before = $lesson->status;
        $lesson->update(['status' => $data['status']]);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'curriculum.lesson_status',
            'before' => ['id' => $lesson->id, 'status' => $before],
            'after' => ['id' => $lesson->id, 'status' => $data['status']],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok(['id' => $lesson->id, 'status' => $lesson->status]);
    }

    /**
     * Publish every lesson in a book that clears the bar, and say plainly how
     * many did not.
     *
     * Never publishes the rest. A book is released one page at a time or not at
     * all; a count of what was held back is what tells an editor where to look.
     */
    public function publishBook(Request $request, SourceDocument $document)
    {
        $ids = Lesson::where('source_document_id', $document->id)->pluck('id');
        $ready = $this->lessonIdsWithAnActivity($ids)
            ->keys()
            ->intersect(
                DB::table('lesson_concept')
                    ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
                    ->where('concepts.is_active', true)
                    ->whereIn('lesson_concept.lesson_id', $ids)
                    ->distinct()->pluck('lesson_concept.lesson_id'),
            );

        $published = Lesson::whereIn('id', $ready)->where('status', '!=', 'published')
            ->update(['status' => 'published']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'curriculum.publish_book',
            'after' => [
                'document' => $document->id,
                'published' => $published,
                'held_back' => $ids->count() - $ready->count(),
            ],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok([
            'document' => $document->id,
            'published_now' => $published,
            'published_total' => $ready->count(),
            'held_back' => $ids->count() - $ready->count(),
        ]);
    }

    /** Withdraw a whole book from learners. */
    public function withdrawBook(Request $request, SourceDocument $document)
    {
        $withdrawn = Lesson::where('source_document_id', $document->id)
            ->where('status', 'published')
            ->update(['status' => 'draft']);

        AuditLog::create([
            'user_id' => $request->user()->id,
            'action' => 'curriculum.withdraw_book',
            'after' => ['document' => $document->id, 'withdrawn' => $withdrawn],
            'ip_address' => $request->ip(),
        ]);

        return $this->ok(['document' => $document->id, 'withdrawn' => $withdrawn]);
    }

    private function isPublishable(int $lessonId): bool
    {
        return $this->lessonIdsWithAnActivity(collect([$lessonId]))->has($lessonId)
            && DB::table('lesson_concept')
                ->join('concepts', 'concepts.id', '=', 'lesson_concept.concept_id')
                ->where('concepts.is_active', true)
                ->where('lesson_concept.lesson_id', $lessonId)
                ->exists();
    }

    /** Lessons holding at least one block that is not the printed page. */
    private function lessonIdsWithAnActivity($only = null)
    {
        return DB::table('lesson_blocks')
            ->whereNotIn('type', self::PASSIVE_BLOCKS)
            ->when($only, fn ($q) => $q->whereIn('lesson_id', $only))
            ->distinct()->pluck('lesson_id')->flip();
    }

    private function lessonIdsWithABlockOfType(string $type, $only = null)
    {
        return DB::table('lesson_blocks')
            ->where('type', $type)
            ->when($only, fn ($q) => $q->whereIn('lesson_id', $only))
            ->distinct()->pluck('lesson_id')->flip();
    }

    /**
     * Lessons that can be asked a choice item, reached the way the engine
     * reaches one: by the concept it drills, not by the lesson it was printed
     * under.
     */
    private function lessonIdsWithARecognitionItem($only = null)
    {
        return DB::table('lesson_concept')
            ->join('exercise_concepts', 'exercise_concepts.concept_id', '=', 'lesson_concept.concept_id')
            ->join('exercises', 'exercises.id', '=', 'exercise_concepts.exercise_id')
            ->join('exercise_options', 'exercise_options.exercise_id', '=', 'exercises.id')
            ->whereIn('exercises.status', Exercise::SERVABLE_STATUSES)
            ->whereNull('exercises.deleted_at')
            ->when($only, fn ($q) => $q->whereIn('lesson_concept.lesson_id', $only))
            ->distinct()->pluck('lesson_concept.lesson_id')->flip();
    }
}
