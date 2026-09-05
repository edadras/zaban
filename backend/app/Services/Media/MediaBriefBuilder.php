<?php

namespace App\Services\Media;

use App\Models\Character;
use App\Models\Dialogue;
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

    public function __construct(
        private PromptBuilder $prompts,
        private VideoTreatment $treatment,
    ) {}

    /**
     * @return array<string,int> counts by outcome
     */
    public function buildAll(): array
    {
        return [
            'character_portrait' => $this->buildCharacterPortraits(),
            'lesson_scene' => $this->buildLessonScenes(),
            'vocabulary_card' => $this->buildVocabularyCards(),
            'dialogue_video' => $this->buildDialogueVideos(),
            'lesson_video' => $this->buildLessonVideos(),
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
     * One clip per extracted dialogue.
     *
     * These are the strongest case for video in the whole course: a printed
     * exchange between two people, where the situation IS the lesson. They are
     * text-to-video rather than image-to-video because a dialogue has no scene
     * still of its own to animate.
     */
    public function buildDialogueVideos(): int
    {
        $model = $this->modelFor('video');
        $levels = $this->levelOrder();
        $n = 0;

        /*
         * A dialogue's own title is its lesson's title, which is often a label
         * rather than a subject - "Expressions", "Nouns and adjectives". The
         * unit it sits in is what actually says what the language is about, so
         * both are used to work out where the scene should be filmed.
         */
        $subjects = $this->dialogueSubjects();

        Dialogue::with('turns.character')->chunkById(100, function ($dialogues) use ($model, $levels, $subjects, &$n) {
            foreach ($dialogues as $dialogue) {
                $cast = $dialogue->turns->pluck('character.name')->filter()->unique()->values()->all();

                $subject = trim(($subjects[$dialogue->source_document_id.':'.$dialogue->source_page] ?? '')
                    .' '.$dialogue->title);

                $spec = $this->prompts->forModel(
                    $this->prompts->dialogueVideo(
                        $this->treatment->settingFor($subject, $dialogue->id),
                        $cast,
                        $subject !== '' ? $subject : null,
                        $this->levelCode($dialogue->cefr_level_id),
                    ),
                    $model,
                );

                $n += $this->upsert(
                    MediaBrief::KIND_DIALOGUE_VIDEO,
                    $dialogue,
                    $model,
                    $spec,
                    $this->treatment->priorityFor(VideoTreatment::TIER_DIALOGUE)
                        + ($levels[$dialogue->cefr_level_id] ?? 9),
                );
            }
        });

        return $n;
    }

    /**
     * Unit title per source page, so a dialogue can be placed by what its unit
     * teaches rather than by what its page happened to be headed.
     *
     * @return array<string,string>
     */
    private function dialogueSubjects(): array
    {
        return DB::table('lessons')
            ->join('units', 'units.id', '=', 'lessons.unit_id')
            ->whereNotNull('lessons.source_page')
            ->orderBy('lessons.source_page')
            ->get(['lessons.source_document_id', 'lessons.source_page', 'units.title'])
            ->mapWithKeys(fn ($r) => [$r->source_document_id.':'.$r->source_page => (string) $r->title])
            ->all();
    }

    /**
     * A clip per lesson that earns one, animated from that lesson's own still.
     *
     * The dependency on the still is the point. Video models drift far worse
     * than image models, and a cast of fourteen would be unrecognisable across
     * a thousand independently generated clips; seeding each one from the scene
     * image that was already generated and approved keeps the same people, the
     * same room and the same framing. A video brief is therefore not renderable
     * until its source image has been imported.
     */
    public function buildLessonVideos(): int
    {
        $model = $this->modelFor('video');
        $levels = $this->levelOrder();
        $n = 0;

        $sceneBriefs = MediaBrief::where('kind', MediaBrief::KIND_LESSON_SCENE)
            ->pluck('id', 'subject_id');

        Lesson::with(['unit', 'concepts'])->chunkById(200, function ($lessons) use ($model, $levels, $sceneBriefs, &$n) {
            foreach ($lessons as $lesson) {
                $treatment = $this->treatment->forLesson($lesson->title, $lesson->unit?->title);

                if ($treatment === null) {
                    $n += $this->skip(
                        MediaBrief::KIND_LESSON_VIDEO,
                        $lesson,
                        'Teaches the language itself rather than a situation - there is no footage of a suffix.',
                    );

                    continue;
                }

                $spec = $this->prompts->forModel(
                    $this->prompts->lessonVideo(
                        $lesson,
                        $treatment['motion'],
                        $lesson->concepts->pluck('label')->take(6)->all(),
                        $treatment['seconds'],
                    ),
                    $model,
                );

                $n += $this->upsert(
                    MediaBrief::KIND_LESSON_VIDEO,
                    $lesson,
                    $model,
                    $spec,
                    $this->treatment->priorityFor($treatment['tier']) + ($levels[$lesson->cefr_level_id] ?? 9),
                    $sceneBriefs[$lesson->id] ?? null,
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
    private function upsert(
        string $kind,
        $subject,
        string $model,
        array $spec,
        int $priority,
        ?int $sourceBriefId = null,
    ): int {
        $seconds = $spec['duration_seconds'] ?? null;
        $resolution = $seconds ? '1080p' : '2k';

        // Duration is part of what makes this a different render, so a change
        // to it must put the brief back in the queue like any other.
        $hash = MediaBrief::hashFor($model, $spec['prompt'], $spec['aspect_ratio'], $resolution.':'.($seconds ?? ''));

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
                'resolution' => $resolution,
                'duration_seconds' => $seconds,
                'source_brief_id' => $sourceBriefId,
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
