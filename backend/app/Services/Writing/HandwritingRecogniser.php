<?php

namespace App\Services\Writing;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\WritingAttempt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reads a photograph of a learner's paper practice.
 *
 * Most learners of English still do a lot of their practice on paper, and that
 * work is currently invisible to the app - it can only assess what was typed
 * into it. This closes that gap: photograph the page, and the same marking that
 * typed writing gets applies to it.
 *
 * The important design decision is that recognition and marking are separate
 * steps with the learner in between. The model's reading is a claim about what
 * is on the page, not a fact, and handwriting recognition is exactly the sort
 * of thing that fails on the letters a learner is least confident forming.
 * Marking an unconfirmed reading would mean penalising someone for the
 * machine's mistake, in the one place they are least able to argue back.
 */
class HandwritingRecogniser
{
    private const FEATURE = 'writing.handwriting';

    /**
     * Below this the reading is offered as a draft to fix rather than a
     * transcription to confirm. Set where it is because a page the model is
     * half-guessing at wastes less of the learner's time retyped than
     * corrected word by word.
     */
    public const LOW_CONFIDENCE = 0.6;

    /** Anthropic's per-image ceiling; a phone photo will exceed it. */
    private const MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(private AiOrchestrator $ai) {}

    public function recognise(WritingAttempt $attempt): bool
    {
        $asset = $attempt->page;

        if (! $asset) {
            return $this->failed($attempt, 'No page image is attached to this attempt.');
        }

        $bytes = $this->read($asset);

        if ($bytes === null) {
            return $this->failed($attempt, 'The page image could not be read from storage.');
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            return $this->failed(
                $attempt,
                'The photo is too large to read. Please retake it at a lower resolution.',
            );
        }

        $attempt->update(['status' => WritingAttempt::STATUS_RECOGNISING]);

        $result = $this->ai->text(new TextRequest(
            feature: self::FEATURE,
            prompt: $this->prompt(),
            system: $this->systemPrompt(),
            schema: $this->schema(),
            // Transcription is not a creative task.
            temperature: 0.0,
            maxTokens: 2000,
            userId: (int) $attempt->user_id,
            metadata: ['writing_attempt_id' => $attempt->id],
            // A photograph of one learner's own page.
            cacheable: false,
            images: [[
                'media_type' => $asset->mime ?: 'image/jpeg',
                'data' => base64_encode($bytes),
            ]],
        ));

        if (! $result->ok || ! is_array($result->json)) {
            Log::warning('writing.handwriting.failed', [
                'writing_attempt_id' => $attempt->id,
                'error' => $result->error,
            ]);

            return $this->failed($attempt, $result->error ?: 'The page could not be read.');
        }

        $text = trim((string) ($result->json['text'] ?? ''));

        if ($text === '') {
            return $this->failed(
                $attempt,
                'No handwriting was found on the page. Check the photo is in focus and the whole page is visible.',
            );
        }

        $confidence = $result->json['confidence'] ?? null;

        $attempt->update([
            'recognised_text' => $text,
            'recognition_confidence' => is_numeric($confidence)
                ? round(max(0, min(1, (float) $confidence)), 3)
                : null,
            // Pre-filled so the learner edits rather than retypes, but not yet
            // authoritative - text_confirmed stays false until they say so.
            'text' => $text,
            'word_count' => str_word_count($text),
            'status' => WritingAttempt::STATUS_AWAITING_CONFIRMATION,
            'error' => null,
        ]);

        return true;
    }

    private function read(\App\Models\MediaAsset $asset): ?string
    {
        try {
            $disk = Storage::disk($asset->disk);

            return $disk->exists($asset->path) ? $disk->get($asset->path) : null;
        } catch (\Throwable $e) {
            Log::warning('writing.handwriting.unreadable', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function systemPrompt(): string
    {
        return implode(' ', [
            'You transcribe handwritten English from photographs of a language learner\'s practice.',
            'Transcribe exactly what is written, including mistakes.',
            'Do NOT correct spelling, grammar, punctuation or word choice - the errors are the',
            'whole point, and silently fixing them would hide the learner\'s real work from the',
            'marker that runs next.',
            'Preserve line and paragraph breaks. Use [?] for a word you genuinely cannot make out.',
            'Ignore anything printed on the page, such as an exercise number or a textbook heading;',
            'transcribe only what the learner wrote by hand.',
            'Report your confidence honestly: a low number costs the learner a retype, an',
            'overconfident one costs them marks they did not lose.',
        ]);
    }

    private function prompt(): string
    {
        return 'Transcribe the handwriting on this page.';
    }

    /**
     * @return array<string,mixed>
     */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'illegible_count' => ['type' => 'integer'],
            ],
            'required' => ['text', 'confidence'],
        ];
    }

    private function failed(WritingAttempt $attempt, string $reason): bool
    {
        $attempt->update([
            'status' => WritingAttempt::STATUS_FAILED,
            'error' => $reason,
        ]);

        return false;
    }
}
