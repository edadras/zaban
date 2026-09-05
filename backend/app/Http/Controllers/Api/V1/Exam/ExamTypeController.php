<?php

namespace App\Http\Controllers\Api\V1\Exam;

use App\Http\Resources\Exam\ExamTypeResource;
use App\Models\ExamType;
use Illuminate\Http\Request;

/** The exam profiles a learner can prepare for, with their CEFR mapping. */
class ExamTypeController extends ExamController
{
    public function index(Request $request)
    {
        $types = ExamType::query()
            ->where('is_active', true)
            ->when($request->filled('language_id'), fn ($q) => $q->where('language_id', $request->integer('language_id')))
            ->with(['sections' => fn ($q) => $q->orderBy('position'), 'sections.skill', 'bands.cefrLevel'])
            ->orderBy('name')
            ->get();

        return $this->ok(ExamTypeResource::collection($types));
    }

    public function show(ExamType $examType)
    {
        $examType->load([
            'sections' => fn ($q) => $q->orderBy('position'),
            'sections.skill',
            'sections.taskTypes',
            'bands.cefrLevel',
        ]);

        return $this->ok(new ExamTypeResource($examType));
    }
}
