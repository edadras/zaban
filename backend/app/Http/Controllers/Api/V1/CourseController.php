<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CourseController extends ApiController
{
    public function index(Request $request)
    {
        $courses = Course::with(['fromLevel', 'toLevel'])
            ->where('is_active', true)
            ->get()
            ->map(fn (Course $c) => [
                'id' => $c->id,
                'slug' => $c->slug,
                'title' => $c->title,
                'description' => $c->description,
                'track' => $c->track,
                'from_cefr' => $c->fromLevel?->code,
                'to_cefr' => $c->toLevel?->code,
                'unit_count' => DB::table('units')
                    ->join('modules', 'modules.id', '=', 'units.module_id')
                    ->join('course_versions', 'course_versions.id', '=', 'modules.course_version_id')
                    ->where('course_versions.course_id', $c->id)->count(),
            ]);

        return $this->ok($courses);
    }

    public function show(Request $request, Course $course)
    {
        $version = $course->versions()->where('status', 'published')->latest('version')->first()
            ?? $course->versions()->latest('version')->first();

        if (! $version) {
            return $this->fail('no_version', 'This course has no published version.', 404);
        }

        $modules = $version->modules()->with(['units' => fn ($q) => $q->orderBy('position')])
            ->orderBy('position')->get();

        return $this->ok([
            'id' => $course->id,
            'title' => $course->title,
            'version' => $version->version,
            'modules' => $modules->map(fn ($m) => [
                'id' => $m->id,
                'title' => $m->title,
                'position' => $m->position,
                'units' => $m->units->map(fn (Unit $u) => [
                    'id' => $u->id,
                    'title' => $u->title,
                    'position' => $u->position,
                    'estimated_minutes' => $u->estimated_minutes,
                    'needs_review' => $u->description !== null,
                ])->values(),
            ])->values(),
        ]);
    }

    public function unit(Request $request, Unit $unit)
    {
        $lessons = $unit->lessons()->orderBy('position')->get();

        return $this->ok([
            'id' => $unit->id,
            'title' => $unit->title,
            'lessons' => $lessons->map(fn (Lesson $l) => [
                'id' => $l->id,
                'title' => $l->title,
                'summary' => $l->summary,
                'section' => $l->source_section,
                'position' => $l->position,
                'estimated_minutes' => $l->estimated_minutes,
                'status' => $l->status,
                'concept_count' => $l->concepts()->count(),
            ])->values(),
        ]);
    }

    public function lesson(Request $request, Lesson $lesson)
    {
        $blocks = $lesson->blocks()->orderBy('position')->get();
        $userId = $request->user()->id;

        // Mastery is attached per concept so the client can show what is already
        // known without computing anything itself.
        $mastery = DB::table('learner_concepts')
            ->where('user_id', $userId)
            ->whereIn('concept_id', $lesson->concepts()->pluck('concepts.id'))
            ->pluck('mastery_score', 'concept_id');

        return $this->ok([
            'id' => $lesson->id,
            'title' => $lesson->title,
            'summary' => $lesson->summary,
            'section' => $lesson->source_section,
            'estimated_minutes' => $lesson->estimated_minutes,
            'blocks' => $blocks->map(fn ($b) => [
                'id' => $b->id,
                'type' => $b->type,
                'position' => $b->position,
                'title' => $b->title,
                'instructions' => $b->instructions,
                'config' => $b->config,
                'media_asset_id' => $b->media_asset_id,
                'exercise_id' => $b->exercise_id,
                'estimated_seconds' => $b->estimated_seconds,
                'is_optional' => (bool) $b->is_optional,
            ])->values(),
            'concepts' => $lesson->concepts->map(fn ($c) => [
                'id' => $c->id,
                'label' => $c->label,
                'difficulty' => (float) $c->difficulty,
                'mastery_score' => (float) ($mastery[$c->id] ?? 0),
            ])->values(),
        ]);
    }
}
