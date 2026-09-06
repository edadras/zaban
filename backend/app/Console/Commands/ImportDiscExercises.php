<?php

namespace App\Console\Commands;

use App\Models\AudioAsset;
use App\Models\AudioMapping;
use App\Models\CefrLevel;
use App\Models\Concept;
use App\Models\ContentReview;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseOption;
use App\Models\ExerciseTemplate;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\MediaAsset;
use App\Models\Skill;
use App\Models\SourceDocument;
use App\Models\Unit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import the interactive disc's exercises as items a learner can be asked.
 *
 * Every exercise the project holds came off a printed page, and a printed
 * exercise imports as one row per instruction: the numbered parts are
 * typography, and their answers are a run of prose sixty pages away. That is
 * the line the readiness report has been stuck on - *answers exist as raw
 * answer-key prose, not per-blank values* - and it is why those rows stay
 * draft and are never served to anyone.
 *
 * The Advanced Grammar disc is the same book's exercises published as data.
 * Each item states its own stem, which blank takes which words, the wrong form
 * and the corrected form for the correction drills, the options for the choice
 * drills, and a recording. So each item becomes one exercise row with its
 * answers attached, approved, and servable.
 *
 * Two rules govern what gets through:
 *
 *   * an item whose answer the disc does not state is imported but left draft,
 *     because marking a learner wrong on an answer we guessed is worse than
 *     not asking them at all;
 *   * an item is attached to the unit the disc files it under, and if that unit
 *     has not been imported yet it is reported rather than attached to whatever
 *     unit happens to be nearest.
 */
class ImportDiscExercises extends Command
{
    protected $signature = 'content:import-disc
        {--path=docs/data/cdrom : directory holding the extracted disc JSON}
        {--course=egu-grammar-advanced : the course whose units these belong to}';

    protected $description = 'Import the interactive disc exercises as servable items';

    /** How the disc's item shapes map onto this project's templates. */
    private const TEMPLATE = [
        'fill_blank' => 'fill_blank',
        'choice' => 'context_choice',
        'multiple_choice' => 'multiple_choice',
        'correction' => 'error_correction',
    ];

    public function handle(): int
    {
        $dir = base_path('..').'/'.trim($this->option('path'), '/');
        $files = glob($dir.'/*.json') ?: [];
        if (! $files) {
            $this->error("No disc data under {$dir}. Run: python3 tools/extract_cdrom.py --disc <dir>");

            return self::FAILURE;
        }

        $course = Course::where('slug', $this->option('course'))->first();
        if (! $course) {
            $this->error("Course {$this->option('course')} is not imported yet. Import the book first.");

            return self::FAILURE;
        }

        $version = $course->versions()->orderByDesc('version')->first();
        $units = $version
            ? Unit::whereIn('module_id', $version->modules()->pluck('id'))->pluck('id', 'position')->all()
            : [];

        if (! $units) {
            $this->error("Course {$course->slug} has no units to attach to.");

            return self::FAILURE;
        }

        $language = (int) Language::where('code', 'en')->value('id');
        $skill = (int) Skill::where('code', 'grammar')->value('id');
        $document = SourceDocument::where('title', 'like', '%Advanced Grammar in Use%')->first();
        $templates = ExerciseTemplate::pluck('id', 'code')->all();

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $this->importDisc($data, $units, $language, $skill, $document, $templates);
        }

        return self::SUCCESS;
    }

    private function importDisc(array $data, array $units, int $language, int $skill, ?SourceDocument $document, array $templates): void
    {
        $counts = ['items' => 0, 'servable' => 0, 'draft' => 0, 'options' => 0,
            'answers' => 0, 'audio' => 0, 'no_unit' => 0];
        $missingUnits = [];

        foreach ($data['exercises'] as $exercise) {
            $unitId = $units[$exercise['unit']] ?? null;
            if ($unitId === null) {
                $counts['no_unit'] += count($exercise['items']);
                $missingUnits[$exercise['unit']] = true;

                continue;
            }

            $unit = Unit::find($unitId);
            $lesson = Lesson::where('unit_id', $unitId)->orderBy('position')->first();

            DB::transaction(function () use ($exercise, $unit, $lesson, $language, $skill, $document, $templates, &$counts) {
                foreach ($exercise['items'] as $item) {
                    $this->importItem($item, $exercise, $unit, $lesson, $language, $skill, $document, $templates, $counts);
                }
            });
        }

        $this->line("<info>▸</info> {$data['source']}");
        $this->line("   items {$counts['items']}  servable {$counts['servable']}  held as draft {$counts['draft']}");
        $this->line("   answers {$counts['answers']}  options {$counts['options']}  recordings {$counts['audio']}");
        if ($counts['no_unit'] > 0) {
            $this->warn("   {$counts['no_unit']} item(s) belong to units this course has not imported: "
                .implode(', ', array_keys($missingUnits)));
        }
    }

    private function importItem(array $item, array $exercise, Unit $unit, ?Lesson $lesson, int $language, int $skill, ?SourceDocument $document, array $templates, array &$counts): void
    {
        $stem = trim((string) ($item['stem'] ?? ''));
        if ($stem === '') {
            $stem = trim((string) ($item['model_answer'] ?? ''));
        }
        if ($stem === '') {
            return;
        }

        $templateId = $templates[self::TEMPLATE[$item['shape']] ?? 'fill_blank'] ?? $templates['fill_blank'];
        $reference = sprintf('disc:%d.%d.%d', $exercise['unit'], $exercise['number'], $item['position']);
        $answers = $this->answersFor($item);

        // An item nothing can mark is kept, because it still teaches when read,
        // but it is never served as a question.
        $servable = $answers !== [];
        $cefr = $unit->cefr_level_id;

        $row = Exercise::updateOrCreate(
            [
                'source_document_id' => $document?->id,
                'source_reference' => $reference,
            ],
            [
                'lesson_id' => $lesson?->id,
                'exercise_template_id' => $templateId,
                'language_id' => $language,
                'skill_id' => $skill,
                'cefr_level_id' => $cefr,
                'stem' => Str::limit($stem, 1000, ''),
                'instructions' => Str::limit($exercise['rubric'] ?: 'Complete the sentence.', 1000, ''),
                // Half the sentences in a correction drill are already right -
                // the rubric asks whether they are, not to change them - and
                // an interface that only offers "edit this" cannot express
                // that answer, so it has to be told.
                'payload' => $item['shape'] === 'correction'
                    ? ['already_correct' => (bool) ($item['unchanged'] ?? false)]
                    : null,
                'difficulty' => $this->difficultyFor($cefr),
                'status' => $servable ? 'approved' : 'draft',
                'generation_method' => 'extracted',
                'copyright_status' => $document?->copyright_status ?? 'owned',
                'source_page' => null,
            ],
        );
        $counts['items']++;
        $counts[$servable ? 'servable' : 'draft']++;

        $row->answers()->delete();
        foreach ($answers as $blankIndex => $values) {
            foreach (array_values($values) as $position => $value) {
                ExerciseAnswer::create([
                    'exercise_id' => $row->id,
                    'blank_index' => $blankIndex,
                    'value' => Str::limit($value, 500, ''),
                    // The disc marks its own items without regard to case or
                    // punctuation; matching more strictly than the book does
                    // would reject answers the book accepts.
                    'match_mode' => 'normalised',
                    'is_primary' => $position === 0,
                    'credit' => 1.000,
                ]);
                $counts['answers']++;
            }
        }

        $options = $this->optionsFor($item);
        $row->options()->delete();
        foreach ($options as $position => [$text, $isCorrect]) {
            ExerciseOption::create([
                'exercise_id' => $row->id,
                'position' => $position,
                'text' => Str::limit($text, 500, ''),
                'is_correct' => $isCorrect,
            ]);
            $counts['options']++;
        }

        if (! empty($item['audio'])) {
            $this->attachAudio($item['audio'], $row, $unit, $counts);
        }

        if ($lesson) {
            $concept = Concept::where('conceptable_type', Lesson::class)
                ->where('conceptable_id', $lesson->id)
                ->value('id');
            if ($concept) {
                $row->concepts()->syncWithoutDetaching([$concept => [
                    'weight' => 1.000, 'created_at' => now(), 'updated_at' => now(),
                ]]);
            }
        }

        ContentReview::updateOrCreate(
            ['reviewable_type' => Exercise::class, 'reviewable_id' => $row->id],
            ['status' => $servable ? 'approved' : 'draft', 'auto_publishable' => $servable],
        );
    }

    /**
     * Every accepted answer, per blank.
     *
     * @return array<int, list<string>>
     */
    private function answersFor(array $item): array
    {
        if ($item['shape'] === 'correction') {
            $correct = trim((string) ($item['answer'] ?? ''));

            return $correct === '' ? [] : [0 => [$correct]];
        }

        if ($item['shape'] === 'multiple_choice') {
            $correct = array_values(array_filter($item['answers'] ?? []));

            return $correct === [] ? [] : [0 => $correct];
        }

        $out = [];
        foreach ($item['blanks'] ?? [] as $blank) {
            $values = array_values(array_filter($blank['answers'] ?? []));
            if ($values === []) {
                // One unanswered blank makes the whole item unmarkable: a
                // learner who filled it correctly would be told they were wrong.
                return [];
            }
            $out[(int) $blank['index']] = $values;
        }

        return $out;
    }

    /**
     * @return list<array{0:string,1:bool}>
     */
    private function optionsFor(array $item): array
    {
        if ($item['shape'] === 'multiple_choice') {
            $correct = array_map('mb_strtolower', $item['answers'] ?? []);

            return array_values(array_map(
                fn ($text) => [$text, in_array(mb_strtolower($text), $correct, true)],
                $item['options'] ?? [],
            ));
        }

        if ($item['shape'] !== 'choice') {
            return [];
        }

        // A choice item shows its alternatives inside the sentence. Only a
        // single-blank item can be offered as a list of options; with two, the
        // learner would also have to say which option belongs to which gap.
        $blanks = $item['blanks'] ?? [];
        if (count($blanks) !== 1) {
            return [];
        }

        $correct = array_map('mb_strtolower', $blanks[0]['answers'] ?? []);

        return array_values(array_map(
            fn ($text) => [$text, in_array(mb_strtolower($text), $correct, true)],
            $blanks[0]['options'] ?? [],
        ));
    }

    private function attachAudio(string $reference, Exercise $exercise, Unit $unit, array &$counts): void
    {
        // The disc cites its clips by filename, and tools/place_audio.py kept
        // those names exactly so the citation still resolves.
        $path = 'sources/audio/grammar_advanced/'.basename($reference);
        if (! is_file(base_path('../'.$path))) {
            return;
        }

        $media = MediaAsset::firstOrCreate(
            ['disk' => 'local', 'path' => $path],
            ['type' => 'audio', 'mime' => 'audio/mpeg', 'origin' => 'ingested',
                'copyright_status' => 'owned'],
        );
        $asset = AudioAsset::firstOrCreate(
            ['media_asset_id' => $media->id],
            ['duration_ms' => 0, 'codec' => 'mp3', 'transcription_status' => 'pending'],
        );

        AudioMapping::updateOrCreate(
            ['audio_asset_id' => $asset->id, 'mappable_type' => Exercise::class, 'mappable_id' => $exercise->id],
            [
                'confidence' => 1.000,
                'method' => 'manifest',
                'evidence' => ['cited_by' => $reference, 'unit' => $unit->position],
                'review_status' => 'auto_approved',
            ],
        );
        $counts['audio']++;
    }

    private function difficultyFor(?int $cefrLevelId): float
    {
        $level = $cefrLevelId ? CefrLevel::find($cefrLevelId) : null;
        if (! $level) {
            return 0.0;
        }

        return round((float) $level->ability_min
            + ((float) $level->ability_max - (float) $level->ability_min) / 2, 3);
    }
}
