<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Exercise;
use App\Models\Language;
use App\Models\PlacementSession;
use App\Services\Placement\PlacementService;
use Illuminate\Http\Request;

class PlacementController extends ApiController
{
    public function __construct(private PlacementService $placement) {}

    public function start(Request $request)
    {
        $code = $request->string('language', 'en')->toString();
        $language = Language::where('code', $code)->firstOrFail();

        $session = $this->placement->start($request->user()->id, $language->id);

        return $this->created([
            'session_id' => $session->id,
            'status' => $session->status,
            'max_items' => $session->max_items,
            'items_administered' => $session->items_administered,
        ]);
    }

    /** The next adaptively selected item, or completion. */
    public function next(Request $request, PlacementSession $session)
    {
        $this->assertOwned($request, $session);

        if ($this->placement->isComplete($session)) {
            $session = $this->placement->complete($session);

            return $this->ok([
                'complete' => true,
                'result' => $this->placement->profile($session),
            ]);
        }

        $item = $this->placement->nextItem($session);
        if (! $item) {
            $session = $this->placement->complete($session);

            return $this->ok([
                'complete' => true,
                'result' => $this->placement->profile($session),
            ]);
        }

        return $this->ok([
            'complete' => false,
            'progress' => [
                'items_administered' => $session->items_administered,
                'max_items' => $session->max_items,
            ],
            'item' => [
                'id' => $item->id,
                'skill' => $item->skill?->code,
                'template' => $item->template?->code,
                'stem' => $item->stem,
                'instructions' => $item->instructions,
                'options' => $item->options()->orderBy('position')->get()
                    ->map(fn ($o) => ['id' => $o->id, 'position' => $o->position, 'text' => $o->text])->values(),
            ],
        ]);
    }

    public function submit(Request $request, PlacementSession $session)
    {
        $this->assertOwned($request, $session);

        $data = $request->validate([
            'exercise_id' => ['required', 'integer', 'exists:exercises,id'],
            'response' => ['required'],
            'response_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($session->status !== 'in_progress') {
            return $this->fail('placement_closed', 'This placement session is already finished.', 422);
        }

        $item = Exercise::with('options')->findOrFail($data['exercise_id']);
        $correct = $this->isCorrect($item, $data['response']);

        $this->placement->submit($session, $item, $correct, $correct ? 1.0 : 0.0, $data['response_ms'] ?? null);

        $session->refresh();
        $complete = $this->placement->isComplete($session);
        if ($complete) {
            $session = $this->placement->complete($session);
        }

        return $this->ok([
            // The learner is not told item-by-item whether they were right:
            // that would let them infer the difficulty ladder and game it.
            'recorded' => true,
            'complete' => $complete,
            'result' => $complete ? $this->placement->profile($session) : null,
        ]);
    }

    public function result(Request $request, PlacementSession $session)
    {
        $this->assertOwned($request, $session);

        return $this->ok($this->placement->profile($session));
    }

    private function isCorrect(Exercise $item, mixed $response): bool
    {
        $correctOption = $item->options->firstWhere('is_correct', true);
        if ($correctOption) {
            return is_numeric($response)
                ? (int) $response === $correctOption->id
                : mb_strtolower(trim((string) $response)) === mb_strtolower(trim($correctOption->text));
        }

        $answer = $item->answers()->where('is_primary', true)->value('value')
            ?? $item->answers()->value('value');

        return $answer !== null
            && mb_strtolower(trim((string) $response)) === mb_strtolower(trim($answer));
    }

    private function assertOwned(Request $request, PlacementSession $session): void
    {
        abort_unless($session->user_id === $request->user()->id, 404);
    }
}
