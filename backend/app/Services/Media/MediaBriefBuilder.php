<?php

namespace App\Services\Media;

use App\Models\Character;
use App\Models\CefrLevel;
use App\Models\Lesson;
use App\Models\MediaBrief;
use App\Models\VocabularySense;
use Illuminate\Support\Facades\DB;

/**
 * Turns the stored curriculum into a complete, ordered render manifest.
 *
 * Deliberately deterministic: every prompt here is derived from content already
 * in the database, so building the manifest costs nothing, needs no API key, and
 * produces the same plan twice. That matters because the manifest has to be
 * reviewable before any of it is paid for - a plan you cannot inspect until you
 * are already spending is not a plan.
 */
class MediaBriefBuilder
{
    /*
     * Render order. The window that pays for this is time-boxed, so if it runs
     * out the manifest must already have made the more useful half first.
     *
     * Portraits lead because the cast anchors every scene that features them -
     * rendering a scene before its characters exist wastes the scene. Lessons
     * come next because a lesson with no artwork is visibly incomplete, whereas
     * a vocabulary card without one still teaches. Within each band, lower CEFR
     * levels go first: a beginner depends on the picture to carry meaning that
     * an advanced learner can get from the text.
     */
    private const PRIORITY_PORTRAIT = 10;

    private const PRIORITY_LESSON = 100;

    private const PRIORITY_VOCABULARY = 300;

    /** @var array<int,string>|null parts_of_speech id => code, loaded once per build */
    private ?array $posCodes = null;

    public function __construct(private PromptBuilder $prompts) {}

    /**
     * @return array<string,int> counts by outcome
     */
    public function buildAll(): array
    {
        return [
            'character_portrait' => $this->buildCharacterPortraits(),
            'lesson_scene' => $this->buildLessonScenes(),
            'vocabulary_card' => $this->buildVocabularyCards(),
        ];
    }

    public function buildCharacterPortraits(): int
    {
        $model = $this->modelFor('character');
        $n = 0;

        foreach (Character::all() as $character) {
            $spec = $this->prompts->forModel(
                $this->prompts->characterPortrait(
                    $character->name,
                    $character->persona ?? 'a recurring character in a language course',
                    $character->appearance_prompt,
                ),
                $model,
            );

            $n += $this->upsert(
                MediaBrief::KIND_CHARACTER_PORTRAIT,
                $character,
                $model,
                $spec,
                self::PRIORITY_PORTRAIT,
            );
        }

        return $n;
    }

    public function buildLessonScenes(): int
    {
        $model = $this->modelFor('scene');
        $levels = $this->levelOrder();
        $n = 0;

        Lesson::with(['unit', 'concepts'])->chunkById(200, function ($lessons) use ($model, $levels, &$n) {
            foreach ($lessons as $lesson) {
                $words = $lesson->concepts->pluck('label')->take(6)->all();

                $spec = $this->prompts->forModel(
                    $this->prompts->lessonScene($lesson, $words),
                    $model,
                );

                $n += $this->upsert(
                    MediaBrief::KIND_LESSON_SCENE,
                    $lesson,
                    $model,
                    $spec,
                    self::PRIORITY_LESSON + ($levels[$lesson->cefr_level_id] ?? 9),
                );
            }
        });

        return $n;
    }

    /**
     * Only senses that carry a real example sentence get a card.
     *
     * The stored definitions are thin (883 rows for 12,416 senses) and some are
     * mis-attributed by the importer, and a wrong gloss produces a confidently
     * wrong picture - worse than no picture, because a learner trusts it. The
     * example sentences are extracted verbatim and are reliable, so they are
     * what the prompt is grounded in. Senses without one are recorded as skipped
     * with the reason, not silently dropped.
     */
    public function buildVocabularyCards(): int
    {
        $model = $this->modelFor('image');
        $levels = $this->levelOrder();
        $n = 0;

        $withExamples = DB::table('examples')
            ->where('exemplifiable_type', VocabularySense::class)
            ->distinct()
            ->pluck('exemplifiable_id')
            ->flip();

        VocabularySense::with(['item', 'examples'])->chunkById(300, function ($senses) use ($model, $levels, $withExamples, &$n) {
            foreach ($senses as $sense) {
                $headword = $sense->item?->headword;
                if (! $headword) {
                    continue;
                }

                $context = $this->groundingFor($sense);

                if ($context === null) {
                    $n += $this->skip(
                        MediaBrief::KIND_VOCABULARY_CARD,
                        $sense,
                        $withExamples->has($sense->id)
                            ? 'Its example sentences are extraction fragments, not usable sentences; grounding the image in one would illustrate the fragment.'
                            : 'No example sentence to ground the image; a card built from the headword alone risks illustrating the wrong sense.',
                    );

                    continue;
                }

                $spec = $this->prompts->forModel(
                    $this->prompts->vocabularyImage(
                        $headword,
                        $context,
                        $this->levelCode($sense->cefr_level_id),
                        $this->partOfSpeechCode($sense),
                    ),
                    $model,
                );

                $n += $this->upsert(
                    MediaBrief::KIND_VOCABULARY_CARD,
                    $sense,
                    $model,
                    $spec,
                    self::PRIORITY_VOCABULARY + ($levels[$sense->cefr_level_id] ?? 9),
                );
            }
        });

        return $n;
    }

    /**
     * The sense's own example sentence, which disambiguates it. "single" means
     * one thing on a hotel page and another on a ticket page; the example is
     * what tells the model which.
     */
    private function groundingFor(VocabularySense $sense): ?string
    {
        $example = $sense->examples->sortBy('position')
            ->first(fn ($e) => PromptBuilder::isUsableExample($e->text));

        return $example ? '"'.trim($example->text).'"' : null;
    }

    /** Word class decides whether the card is an object or a situation. */
    private function partOfSpeechCode(VocabularySense $sense): ?string
    {
        if (! $sense->part_of_speech_id) {
            return null;
        }

        $this->posCodes ??= DB::table('parts_of_speech')->pluck('code', 'id')->all();

        return $this->posCodes[$sense->part_of_speech_id] ?? null;
    }

    /**
     * Idempotent: an unchanged request leaves an already-rendered brief alone,
     * so re-running the builder after a partial generation run never discards
     * work that has been paid for.
     */
    private function upsert(string $kind, $subject, string $model, array $spec, int $priority): int
    {
        $hash = MediaBrief::hashFor($model, $spec['prompt'], $spec['aspect_ratio'], '2k');

        $existing = MediaBrief::where('kind', $kind)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        if ($existing && $existing->request_hash === $hash) {
            return 0;
        }

        // The request changed, so any previously generated result is for a
        // different image and the brief goes back into the queue.
        MediaBrief::updateOrCreate(
            [
                'kind' => $kind,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ],
            [
                'model' => $model,
                'prompt' => $spec['prompt'],
                'negative' => $spec['negative'] ?? null,
                'aspect_ratio' => $spec['aspect_ratio'],
                'resolution' => '2k',
                'priority' => $priority,
                'status' => MediaBrief::STATUS_PENDING,
                'skip_reason' => null,
                'request_hash' => $hash,
                'external_job_id' => null,
                'result_url' => null,
                'error' => null,
                'generated_at' => null,
            ],
        );

        return 1;
    }

    private function skip(string $kind, $subject, string $reason): int
    {
        $existing = MediaBrief::where('kind', $kind)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->first();

        // Never downgrade something already rendered into a skip.
        if ($existing && in_array($existing->status, [MediaBrief::STATUS_GENERATED, MediaBrief::STATUS_IMPORTED], true)) {
            return 0;
        }

        MediaBrief::updateOrCreate(
            [
                'kind' => $kind,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
            ],
            [
                'model' => '', 'prompt' => '', 'aspect_ratio' => '1:1', 'resolution' => '2k',
                'priority' => 999,
                'status' => MediaBrief::STATUS_SKIPPED,
                'skip_reason' => $reason,
                'request_hash' => '',
            ],
        );

        return 0;
    }

    /** CEFR id => rank, so beginner artwork is rendered before advanced. */
    private function levelOrder(): array
    {
        return CefrLevel::orderBy('id')->pluck('id')->values()
            ->mapWithKeys(fn ($id, $i) => [$id => $i])->all();
    }

    private function levelCode(?int $id): ?string
    {
        return $id ? CefrLevel::find($id)?->code : null;
    }

    private function modelFor(string $kind): string
    {
        return (string) config("ai.providers.higgsfield.models.{$kind}", 'gpt_image_2');
    }
}
