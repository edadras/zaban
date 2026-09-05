<?php

namespace App\Http\Controllers\Api\V1\Writing;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Writing\StoreWritingAttemptRequest;
use App\Http\Resources\Writing\WritingAttemptResource;
use App\Jobs\Writing\ProcessWritingAttempt;
use App\Models\MediaAsset;
use App\Models\WritingAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Submitting writing, confirming a scanned page, and reading the marks.
 *
 * Marking runs on a queue: the learner gets an attempt id immediately and polls,
 * the same shape the speech path already uses.
 */
class WritingAttemptController extends ApiController
{
    public function index(Request $request)
    {
        $attempts = WritingAttempt::where('user_id', $request->user()->id)
            ->latest('id')
            ->paginate(20);

        return WritingAttemptResource::collection($attempts);
    }

    public function store(StoreWritingAttemptRequest $request)
    {
        $userId = (int) $request->user()->id;
        $photo = $request->file('page');

        $attempt = WritingAttempt::create([
            'user_id' => $userId,
            'production_prompt_id' => $request->integer('production_prompt_id') ?: null,
            'exercise_id' => $request->integer('exercise_id') ?: null,
            'lesson_id' => $request->integer('lesson_id') ?: null,
            'learning_session_id' => $request->integer('learning_session_id') ?: null,
            'cefr_level_id' => $request->integer('cefr_level_id') ?: null,
            'source' => $photo ? WritingAttempt::SOURCE_PHOTO : WritingAttempt::SOURCE_TYPED,
            'media_asset_id' => $photo ? $this->storePage($userId, $photo)->id : null,
            'text' => $photo ? null : trim((string) $request->input('text')),
            'word_count' => $photo ? null : str_word_count((string) $request->input('text')),
            // Typed text is the learner's own words by definition. A photo is
            // not confirmed until they have read back what the machine made of it.
            'text_confirmed' => ! $photo,
            'status' => WritingAttempt::STATUS_PENDING,
        ]);

        ProcessWritingAttempt::dispatch($attempt->id);

        return $this->created(new WritingAttemptResource($attempt->refresh()));
    }

    public function show(Request $request, WritingAttempt $attempt)
    {
        if (! $this->owns($request, $attempt)) {
            return $this->fail('forbidden', 'This attempt belongs to another learner.', 403);
        }

        return $this->ok(new WritingAttemptResource($attempt));
    }

    /**
     * The learner accepts or corrects what was read off their page.
     *
     * This is the step that makes a photographed attempt markable. Until it
     * happens the text is a machine's reading, and marking it would penalise
     * the learner for the recogniser's mistakes rather than their own.
     */
    public function confirm(Request $request, WritingAttempt $attempt)
    {
        if (! $this->owns($request, $attempt)) {
            return $this->fail('forbidden', 'This attempt belongs to another learner.', 403);
        }

        if ($attempt->source !== WritingAttempt::SOURCE_PHOTO) {
            return $this->fail('not_applicable', 'Only a photographed page needs confirming.', 422);
        }

        if ($attempt->status === WritingAttempt::STATUS_SCORED) {
            return $this->fail('already_scored', 'This attempt has already been marked.', 409);
        }

        $validated = $request->validate([
            'text' => ['required', 'string', 'min:1', 'max:20000'],
        ]);

        $text = trim($validated['text']);

        $attempt->update([
            'text' => $text,
            'word_count' => str_word_count($text),
            'text_confirmed' => true,
            'status' => WritingAttempt::STATUS_PENDING,
            'error' => null,
        ]);

        ProcessWritingAttempt::dispatch($attempt->id);

        return $this->ok(new WritingAttemptResource($attempt->refresh()));
    }

    private function owns(Request $request, WritingAttempt $attempt): bool
    {
        return (int) $attempt->user_id === (int) $request->user()->id;
    }

    private function storePage(int $userId, $file): MediaAsset
    {
        $bytes = file_get_contents($file->getRealPath());
        $checksum = hash('sha256', $bytes);

        $path = sprintf('writing/%d/%s.%s', $userId, $checksum, $file->getClientOriginalExtension() ?: 'jpg');
        Storage::disk('local')->put($path, $bytes);

        $size = @getimagesizefromstring($bytes);

        return MediaAsset::create([
            'disk' => 'local',
            'path' => $path,
            'type' => 'image',
            'mime' => $size['mime'] ?? $file->getMimeType() ?? 'image/jpeg',
            'bytes' => strlen($bytes),
            'width' => $size[0] ?? null,
            'height' => $size[1] ?? null,
            'checksum' => $checksum,
            'origin' => 'learner_upload',
            // The learner's own page; it is theirs, not course content.
            'copyright_status' => 'learner',
        ]);
    }
}
