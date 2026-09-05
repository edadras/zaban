<?php

namespace App\Console\Commands;

use App\Models\AudioAsset;
use App\Models\AudioMapping;
use App\Models\CefrLevel;
use App\Models\Concept;
use App\Models\ContentReview;
use App\Models\Course;
use App\Models\CourseVersion;
use App\Models\Definition;
use App\Models\Example;
use App\Models\Exercise;
use App\Models\ExerciseAnswer;
use App\Models\ExerciseTemplate;
use App\Models\IngestionJob;
use App\Models\IngestionStage;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\MediaAsset;
use App\Models\Module;
use App\Models\Skill;
use App\Models\SourceDocument;
use App\Models\SourceFile;
use App\Models\SourcePage;
use App\Models\SourceSegment;
use App\Models\Unit;
use App\Models\VocabularyItem;
use App\Models\VocabularySense;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads the structured curriculum produced by tools/extract_content.py into the
 * relational schema.
 *
 * Idempotent: re-running updates rather than duplicating, so an import can be
 * repeated after the extractor improves. Every row carries provenance back to the
 * page it came from.
 */
class ImportCurriculum extends Command
{
    protected $signature = 'content:import
        {--path=docs/data/curriculum : directory holding the extracted JSON}
        {--book= : import only this book key}
        {--fresh : delete previously imported curriculum first}';

    protected $description = 'Import extracted book curriculum into the database';

    /** Units per module, used to give each course a navigable spine. */
    private const UNITS_PER_MODULE = 10;

    private array $cefr = [];
    private array $stats = [];
    private int $languageId;
    private int $vocabSkillId;

    public function handle(): int
    {
        $dir = base_path('..').'/'.trim($this->option('path'), '/');
        if (! is_dir($dir)) {
            $this->error("Curriculum directory not found: {$dir}");

            return self::FAILURE;
        }

        $this->cefr = CefrLevel::pluck('id', 'code')->all();
        $english = Language::where('code', 'en')->first();
        if (! $english || ! $this->cefr) {
            $this->error('Reference data missing. Run: php artisan db:seed --class=ReferenceDataSeeder');

            return self::FAILURE;
        }
        $this->languageId = $english->id;
        $this->vocabSkillId = Skill::where('code', 'vocabulary')->value('id');

        $files = glob($dir.'/*.json');
        if ($book = $this->option('book')) {
            $files = array_filter($files, fn ($f) => basename($f, '.json') === $book);
        }
        if (! $files) {
            $this->error('No curriculum JSON found.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->purge();
        }

        foreach ($files as $file) {
            $this->importBook(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR));
        }

        $this->renderSummary();

        return self::SUCCESS;
    }

    private function purge(): void
    {
        $this->warn('Removing previously imported curriculum…');
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ([
            'audio_mappings', 'audio_assets', 'exercise_answers', 'exercise_options',
            'exercise_concepts', 'exercises', 'lesson_concept', 'lesson_blocks', 'lessons',
            'units', 'modules', 'course_versions', 'courses', 'concepts', 'examples',
            'definitions', 'vocabulary_senses', 'vocabulary_items', 'content_reviews',
            'ingestion_issues', 'ingestion_stages', 'ingestion_jobs', 'source_pages',
            'source_segments', 'source_files', 'source_documents', 'media_assets',
        ] as $t) {
            DB::table($t)->truncate();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function importBook(array $data): void
    {
        $key = $data['key'];
        $this->line("<info>▸</info> {$key} ({$data['cefr']}) — {$data['course']}");

        [$fromCode, $toCode] = $this->cefrRange($data['cefr']);

        $doc = SourceDocument::updateOrCreate(
            ['title' => "English Vocabulary in Use — {$data['course']}"],
            [
                'publisher' => null,
                'language_id' => $this->languageId,
                'cefr_level_id' => $this->cefr[$toCode],
                'copyright_status' => $data['copyright_status'] ?? 'owned',
                'status' => 'processing',
            ],
        );

        // The PDF is a single file; the audio is a directory of mp3s that live in
        // the project, so it is registered as an audio-pack container row.
        $pdfRel = $data['source_pdf'];
        $pdfAbs = base_path('..').'/'.$pdfRel;
        SourceFile::updateOrCreate(
            ['source_document_id' => $doc->id, 'path' => $pdfRel],
            [
                'disk' => 'local',
                'original_name' => basename($pdfRel),
                'kind' => 'pdf',
                'mime' => 'application/pdf',
                'bytes' => is_file($pdfAbs) ? filesize($pdfAbs) : 0,
                'checksum' => is_file($pdfAbs) ? hash_file('sha256', $pdfAbs) : '',
                'status' => 'processed',
            ],
        );

        $audioRel = $data['source_audio'];
        $audioAbs = base_path('..').'/'.$audioRel;
        $audioBytes = 0;
        if (is_dir($audioAbs)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($audioAbs, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    $audioBytes += $f->getSize();
                }
            }
        }
        SourceFile::updateOrCreate(
            ['source_document_id' => $doc->id, 'path' => $audioRel],
            [
                'disk' => 'local',
                'original_name' => basename($audioRel),
                'relative_path' => $audioRel,
                'kind' => 'audio',
                'mime' => 'inode/directory',
                'bytes' => $audioBytes,
                'checksum' => '',
                'status' => 'processed',
            ],
        );

        $job = IngestionJob::create([
            'source_document_id' => $doc->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $course = Course::updateOrCreate(
            ['slug' => Str::slug("evu-{$key}")],
            [
                'language_id' => $this->languageId,
                'from_cefr_level_id' => $this->cefr[$fromCode],
                'to_cefr_level_id' => $this->cefr[$toCode],
                'title' => "English Vocabulary — {$data['course']}",
                'description' => "Adaptive vocabulary curriculum derived from the {$data['course']} source book.",
                'track' => 'general',
                'is_active' => true,
            ],
        );

        $version = CourseVersion::updateOrCreate(
            ['course_id' => $course->id, 'version' => 1],
            ['status' => 'published', 'published_at' => now()],
        );

        $counts = ['units' => 0, 'lessons' => 0, 'vocab' => 0, 'senses' => 0,
                   'definitions' => 0, 'examples' => 0, 'concepts' => 0,
                   'exercises' => 0, 'audio' => 0, 'pages' => 0, 'segments' => 0,
                   'chars' => 0, 'images' => 0, 'image_blocks' => 0];

        // Every page of the book is stored verbatim first. Whatever the structural
        // parser does or does not recognise, no source text is ever unrepresented.
        $pdfFile = SourceFile::where('source_document_id', $doc->id)->where('kind', 'pdf')->first();
        DB::transaction(function () use ($data, $pdfFile, &$counts) {
            foreach (array_chunk($data['pages'] ?? [], 100) as $chunk) {
                foreach ($chunk as $pg) {
                    SourcePage::updateOrCreate(
                        ['source_file_id' => $pdfFile->id, 'page_number' => $pg['number']],
                        [
                            'text' => $pg['text'],
                            'char_count' => $pg['char_count'],
                            'used_vision' => false,
                            'status' => 'processed',
                        ],
                    );
                    $counts['pages']++;
                    $counts['chars'] += $pg['char_count'];
                }
            }
        });
        $pageIds = SourcePage::where('source_file_id', $pdfFile->id)->pluck('id', 'page_number')->all();

        DB::transaction(function () use ($data, $doc, $version, $toCode, $pageIds, &$counts) {
            $modules = [];
            foreach ($data['units'] as $u) {
                $bucket = (int) floor(($u['number'] - 1) / self::UNITS_PER_MODULE);
                if (! isset($modules[$bucket])) {
                    $lo = $bucket * self::UNITS_PER_MODULE + 1;
                    $hi = $lo + self::UNITS_PER_MODULE - 1;
                    $modules[$bucket] = Module::updateOrCreate(
                        ['course_version_id' => $version->id, 'position' => $bucket],
                        ['title' => "Units {$lo}–{$hi}", 'cefr_level_id' => $this->cefr[$toCode]],
                    );
                }
                $counts['units']++;
                $this->importUnit($u, $modules[$bucket], $doc, $toCode, $counts, $pageIds);
            }
        });

        $this->importImages($key, $doc, $counts);

        // Sweep the complete audio inventory. Files whose unit resolved were mapped
        // during unit import; this catches anything left over so no audio is dropped.
        $this->registerRemainingAudio($data, $counts);

        $doc->update(['status' => 'processed']);
        $job->update(['status' => 'completed', 'finished_at' => now(), 'stats' => $counts]);
        $this->recordStages($job, $counts);

        $this->stats[$key] = $counts;
    }

    private function importUnit(array $u, Module $module, SourceDocument $doc, string $cefrCode, array &$counts, array $pageIds = []): void
    {
        $unit = Unit::updateOrCreate(
            ['module_id' => $module->id, 'position' => $u['number']],
            [
                'title' => $u['title'],
                'description' => ($u['needs_title_review'] ?? false)
                    ? 'Unit heading could not be read from the source page; title needs review.'
                    : null,
                'cefr_level_id' => $this->cefr[$cefrCode],
                'estimated_minutes' => max(5, count($u['sections']) * 4),
            ],
        );

        $audioBySection = [];
        foreach ($u['audio'] as $a) {
            $audioBySection[$a['section'] ?? '_'][] = $a;
        }

        $position = 0;
        foreach ($u['sections'] as $sec) {
            $lesson = Lesson::updateOrCreate(
                ['unit_id' => $unit->id, 'position' => $position++],
                [
                    'title' => $sec['title'] ?: "{$u['title']} ({$sec['letter']})",
                    'summary' => Str::limit(strip_tags($sec['body']), 380),
                    'cefr_level_id' => $this->cefr[$cefrCode],
                    'kind' => 'core',
                    'estimated_minutes' => 4,
                    'status' => 'draft',
                    'source_document_id' => $doc->id,
                    'source_page' => $u['source_page'],
                    'source_section' => $sec['letter'],
                    'generation_method' => 'extracted',
                    'copyright_status' => $doc->copyright_status,
                ],
            );
            $counts['lessons']++;

            // The lesson row carries a short summary for listings; the complete
            // teaching text is preserved as a segment so nothing is truncated away.
            $sectionSegment = SourceSegment::updateOrCreate(
                [
                    'source_document_id' => $doc->id,
                    'segment_type' => 'section',
                    'label' => "U{$u['number']}.{$sec['letter']}",
                ],
                [
                    'source_page_id' => $pageIds[$u['source_page']] ?? null,
                    'position' => $position,
                    'text' => $sec['body'],
                    'cefr_level_id' => $this->cefr[$cefrCode],
                    'classification_confidence' => 1.000,
                ],
            );
            $counts['segments']++;

            $lesson->blocks()->updateOrCreate(
                ['type' => 'source_text', 'position' => 0],
                [
                    'title' => $sec['title'] ?: null,
                    'config' => [
                        'source_segment_id' => $sectionSegment->id,
                        'text' => $sec['body'],
                        'glosses' => $sec['glosses'],
                    ],
                    'estimated_seconds' => 90,
                ],
            );

            ContentReview::updateOrCreate(
                ['reviewable_type' => Lesson::class, 'reviewable_id' => $lesson->id],
                ['status' => 'draft', 'auto_publishable' => false],
            );

            $conceptIds = [];
            foreach ($sec['vocabulary'] as $v) {
                $conceptIds[] = $this->importVocabulary($v, $doc, $u, $sec, $cefrCode, $counts, $lesson->id);
            }
            $conceptIds = array_values(array_filter(array_unique($conceptIds)));
            if ($conceptIds) {
                $lesson->concepts()->syncWithoutDetaching(
                    array_fill_keys($conceptIds, ['role' => 'target', 'created_at' => now(), 'updated_at' => now()]),
                );
            }

            foreach ($audioBySection[$sec['letter']] ?? [] as $a) {
                $this->importAudio($a, $unit, $lesson, $counts);
            }
        }

        // Any remaining bucket is audio whose section marker matched no extracted
        // lesson (numeric suffixes, or a section the parser missed). Map it to the
        // unit rather than dropping it - losing source audio is never acceptable.
        $claimed = array_column($u['sections'], 'letter');
        foreach ($audioBySection as $sectionKey => $files) {
            if ($sectionKey !== '_' && in_array((string) $sectionKey, $claimed, true)) {
                continue;
            }
            foreach ($files as $a) {
                $this->importAudio($a, $unit, null, $counts);
            }
        }

        $answers = collect($u['answers'])->keyBy('number');
        foreach ($u['exercises'] as $ex) {
            $this->importExercise($ex, $answers->get($ex['number']), $unit, $doc, $u, $cefrCode, $counts, $pageIds);
        }
    }

    private function importVocabulary(array $v, SourceDocument $doc, array $u, array $sec, string $cefrCode, array &$counts, ?int $lessonId = null): ?int
    {
        $term = trim($v['term']);
        if ($term === '' || mb_strlen($term) > 190) {
            return null;
        }
        $normalised = Str::lower($term);

        $item = VocabularyItem::firstOrCreate(
            ['language_id' => $this->languageId, 'normalised' => $normalised, 'primary_part_of_speech_id' => null],
            ['headword' => $term, 'cefr_level_id' => $this->cefr[$cefrCode]],
        );
        if ($item->wasRecentlyCreated) {
            $counts['vocab']++;
        } else {
            // A word met earlier in the ladder keeps its lower (easier) CEFR banding.
            $existing = array_search($item->cefr_level_id, $this->cefr, true);
            if ($existing && $this->ordinal($cefrCode) < $this->ordinal($existing)) {
                $item->update(['cefr_level_id' => $this->cefr[$cefrCode]]);
            }
        }

        // A headword taught in two different sections is teaching two senses
        // ("single" as marital status vs. a single ticket vs. single quotation
        // marks). Key the sense to the teaching context rather than collapsing
        // them, otherwise unrelated examples pile onto one meaning.
        $senseKey = $lessonId ?? 0;
        $sense = VocabularySense::firstOrCreate(
            ['vocabulary_item_id' => $item->id, 'sense_number' => $this->senseNumberFor($item->id, $senseKey)],
            ['cefr_level_id' => $this->cefr[$cefrCode], 'topic_id' => null],
        );
        if ($sense->wasRecentlyCreated) {
            $counts['senses']++;
        }

        // The Advanced/Upper editions gloss a term inline, directly after it. The
        // gloss may sit on the term's own line or elsewhere in the section body.
        $gloss = $this->glossFor($term, $v['example'] ?? '')
            ?? $this->glossFor($term, $sec['body'] ?? '');
        if ($gloss) {
            $def = Definition::firstOrCreate(
                ['vocabulary_sense_id' => $sense->id, 'language_id' => $this->languageId, 'text' => $gloss],
                ['cefr_level_id' => $this->cefr[$cefrCode], 'generation_method' => 'extracted'],
            );
            if ($def->wasRecentlyCreated) {
                $counts['definitions']++;
            }
        }

        $example = trim(preg_replace('/\s*\[[^\]]*\]/', '', $v['example'] ?? ''));
        if ($example !== '' && $this->isUsableExample($example, $term)) {
            $ex = Example::firstOrCreate(
                [
                    'exemplifiable_type' => VocabularySense::class,
                    'exemplifiable_id' => $sense->id,
                    'text' => Str::limit($example, 500, ''),
                ],
                [
                    'language_id' => $this->languageId,
                    'cefr_level_id' => $this->cefr[$cefrCode],
                    'generation_method' => 'extracted',
                    'copyright_status' => $doc->copyright_status,
                ],
            );
            if ($ex->wasRecentlyCreated) {
                $counts['examples']++;
            }
        }

        $concept = Concept::firstOrCreate(
            ['conceptable_type' => VocabularySense::class, 'conceptable_id' => $sense->id],
            [
                'language_id' => $this->languageId,
                'skill_id' => $this->vocabSkillId,
                'cefr_level_id' => $this->cefr[$cefrCode],
                'label' => $term,
                // Seed difficulty from the CEFR band midpoint; live attempts recalibrate it.
                'difficulty' => $this->difficultyFor($cefrCode),
                'importance' => 0.5,
            ],
        );
        if ($concept->wasRecentlyCreated) {
            $counts['concepts']++;
        }

        return $concept->id;
    }

    /** Stable sense number per (item, teaching context). */
    private function senseNumberFor(int $itemId, int $contextKey): int
    {
        static $map = [];
        $k = $itemId.':'.$contextKey;
        if (! isset($map[$k])) {
            $map[$k] = count(array_filter(array_keys($map), fn ($x) => str_starts_with($x, $itemId.':'))) + 1;
        }

        return $map[$k];
    }

    /**
     * Reject fragments left behind by bracket stripping and column bleed - an
     * "example" like "] or a single [" teaches nothing.
     */
    private function isUsableExample(string $text, string $term): bool
    {
        if (mb_strlen($text) < mb_strlen($term) + 6) {
            return false;
        }
        if (substr_count($text, '[') !== substr_count($text, ']')) {
            return false;
        }
        if (preg_match('/^[\\s\\]\\[\\/,;:.\\-]/u', $text)) {
            return false;
        }
        // must read as a phrase, not a pile of punctuation
        $words = preg_split('/\\s+/', trim($text));

        return count($words) >= 3 && preg_match('/[a-z]{2}/i', $text) === 1;
    }

    private function glossFor(string $term, string $line): ?string
    {
        if (! str_contains($line, '[')) {
            return null;
        }
        $pos = mb_stripos($line, $term);
        if ($pos === false) {
            return null;
        }
        $after = mb_substr($line, $pos + mb_strlen($term));
        if (preg_match('/^[^\[\]]{0,40}\[([^\[\]]{3,200})\]/', $after, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    /**
     * Register the book's artwork.
     *
     * Full-page scans attach to their source_page so the vision fallback and the
     * admin reviewer can see the original layout. Spot illustrations attach to the
     * lessons on that page, because that is the artwork an exercise refers to.
     */
    private function importImages(string $bookKey, SourceDocument $doc, array &$counts): void
    {
        $manifestPath = base_path('..').'/docs/data/images.json';
        if (! is_file($manifestPath)) {
            return;
        }
        $manifest = json_decode(file_get_contents($manifestPath), true);
        $dirKey = match ($bookKey) {
            'pre_int_int' => 'pre_intermediate_intermediate',
            'upper_int' => 'upper_intermediate',
            default => $bookKey,
        };
        $images = $manifest[$dirKey]['images'] ?? [];
        if (! $images) {
            return;
        }

        $pdfFile = SourceFile::where('source_document_id', $doc->id)->where('kind', 'pdf')->first();
        $pages = SourcePage::where('source_file_id', $pdfFile->id)->pluck('id', 'page_number')->all();

        $lessonsByPage = Lesson::where('source_document_id', $doc->id)
            ->whereNotNull('source_page')
            ->get()
            ->groupBy('source_page');

        foreach ($images as $img) {
            $media = MediaAsset::updateOrCreate(
                ['disk' => 'local', 'path' => $img['path']],
                [
                    'type' => 'image',
                    'mime' => str_ends_with($img['path'], '.png') ? 'image/png' : 'image/jpeg',
                    'bytes' => $img['bytes'] ?? null,
                    'width' => $img['width'] ?? null,
                    'height' => $img['height'] ?? null,
                    'origin' => 'ingested',
                    'copyright_status' => $doc->copyright_status,
                    'metadata' => ['page' => $img['page'], 'is_page_scan' => $img['is_page_scan']],
                ],
            );
            $counts['images']++;

            if ($img['is_page_scan']) {
                if ($pageId = $pages[$img['page']] ?? null) {
                    SourcePage::whereKey($pageId)->update(['page_image_media_asset_id' => $media->id]);
                }

                continue;
            }

            // Spot artwork: hang it on every lesson taught from that page, as an
            // illustration block the lesson renderer can show.
            foreach ($lessonsByPage->get($img['page'], collect()) as $lesson) {
                $lesson->blocks()->updateOrCreate(
                    ['type' => 'image_scene', 'position' => 100 + $img['index']],
                    [
                        'media_asset_id' => $media->id,
                        'config' => ['source_page' => $img['page'], 'origin' => 'book_illustration'],
                        'estimated_seconds' => 20,
                    ],
                );
                $counts['image_blocks']++;
            }
        }
    }

    /** Register every mp3 in the book's inventory, mapped or not. */
    private function registerRemainingAudio(array $data, array &$counts): void
    {
        foreach ($data['audio_inventory'] ?? [] as $a) {
            $path = $a['extracted_path'] ?? $a['path'];
            if (MediaAsset::where('disk', 'local')->where('path', $path)->exists()) {
                continue;
            }
            $media = MediaAsset::create([
                'disk' => 'local',
                'path' => $path,
                'type' => 'audio',
                'mime' => 'audio/mpeg',
                'origin' => 'ingested',
                'copyright_status' => 'owned',
                'metadata' => ['archive_path' => $a['path'], 'unit' => $a['unit'] ?? null,
                               'section' => $a['section'] ?? null],
            ]);
            AudioAsset::create([
                'media_asset_id' => $media->id,
                'duration_ms' => 0,
                'codec' => 'mp3',
                'transcription_status' => 'pending',
            ]);
            $counts['audio']++;
        }
    }

    private function importAudio(array $a, Unit $unit, ?Lesson $lesson, array &$counts): void
    {
        $media = MediaAsset::firstOrCreate(
            ['disk' => 'local', 'path' => $a['extracted_path'] ?? $a['path']],
            [
                'type' => 'audio',
                'mime' => 'audio/mpeg',
                'origin' => 'ingested',
                'copyright_status' => 'owned',
            ],
        );

        $asset = AudioAsset::firstOrCreate(
            ['media_asset_id' => $media->id],
            ['duration_ms' => 0, 'codec' => 'mp3', 'transcription_status' => 'pending'],
        );
        if ($asset->wasRecentlyCreated) {
            $counts['audio']++;
        }

        // Filename encodes unit and section, so this mapping is exact, not inferred.
        foreach (array_filter([[$unit, Unit::class], $lesson ? [$lesson, Lesson::class] : null]) as [$target, $class]) {
            AudioMapping::updateOrCreate(
                ['audio_asset_id' => $asset->id, 'mappable_type' => $class, 'mappable_id' => $target->id],
                [
                    'confidence' => 1.000,
                    'method' => 'filename',
                    'evidence' => [
                        'file' => basename($a['path']),
                        'section' => $a['section'],
                        'archive_path' => $a['path'],
                    ],
                    'review_status' => 'auto_approved',
                ],
            );
        }
    }

    private function importExercise(array $ex, ?array $answer, Unit $unit, SourceDocument $doc, array $u, string $cefrCode, array &$counts, array $pageIds = []): void
    {
        $instructions = trim($ex['instructions'] ?? '');
        if ($instructions === '') {
            return;
        }

        $template = ExerciseTemplate::where('code', $this->templateFor($instructions))->first()
            ?? ExerciseTemplate::where('code', 'fill_blank')->first();

        $lesson = Lesson::where('unit_id', $unit->id)->orderBy('position')->first();

        $row = Exercise::updateOrCreate(
            [
                'lesson_id' => $lesson?->id,
                'source_document_id' => $doc->id,
                'source_reference' => "{$u['number']}.{$ex['number']}",
            ],
            [
                'exercise_template_id' => $template->id,
                'language_id' => $this->languageId,
                'skill_id' => $this->vocabSkillId,
                'cefr_level_id' => $this->cefr[$cefrCode],
                'stem' => Str::limit($instructions, 1000, ''),
                'instructions' => Str::limit($instructions, 1000, ''),
                'difficulty' => $this->difficultyFor($cefrCode),
                // Extracted source drills stay unpublished until transformed into
                // interactive items; nothing here is learner-facing yet.
                'status' => 'draft',
                'generation_method' => 'extracted',
                'copyright_status' => $doc->copyright_status,
                'source_page' => $u['source_page'],
            ],
        );
        $counts['exercises']++;

        // The complete drill - rubric plus every numbered item - is kept as a
        // segment. The exercise row holds only the rubric, so without this the
        // items themselves would be lost.
        SourceSegment::updateOrCreate(
            [
                'source_document_id' => $doc->id,
                'segment_type' => 'exercise',
                'label' => "{$u['number']}.{$ex['number']}",
            ],
            [
                'source_page_id' => $pageIds[$u['source_page'] + 1] ?? ($pageIds[$u['source_page']] ?? null),
                'position' => $ex['number'],
                'text' => $ex['body'] ?? $instructions,
                'cefr_level_id' => $this->cefr[$cefrCode],
                'classification_confidence' => 1.000,
            ],
        );
        $counts['segments']++;

        if ($answer && trim($answer['text'] ?? '') !== '') {
            SourceSegment::updateOrCreate(
                [
                    'source_document_id' => $doc->id,
                    'segment_type' => 'answer_key',
                    'label' => "{$u['number']}.{$ex['number']}",
                ],
                [
                    'position' => $ex['number'],
                    'text' => $answer['text'],
                    'cefr_level_id' => $this->cefr[$cefrCode],
                    'classification_confidence' => 1.000,
                ],
            );
            $counts['segments']++;
        }

        if ($answer && trim($answer['text'] ?? '') !== '') {
            ExerciseAnswer::updateOrCreate(
                ['exercise_id' => $row->id, 'blank_index' => 0, 'value' => Str::limit($answer['text'], 500, '')],
                ['match_mode' => 'normalised', 'is_primary' => true, 'credit' => 1.000],
            );
        }

        ContentReview::updateOrCreate(
            ['reviewable_type' => Exercise::class, 'reviewable_id' => $row->id],
            ['status' => 'draft', 'auto_publishable' => false],
        );
    }

    /** Best-effort template inference from the rubric wording. */
    private function templateFor(string $instructions): string
    {
        $t = Str::lower($instructions);

        return match (true) {
            str_contains($t, 'listen') => 'listen_and_choose',
            str_contains($t, 'correct the mistake'), str_contains($t, 'correct these') => 'error_correction',
            str_contains($t, 'match') => 'match',
            str_contains($t, 'complete') , str_contains($t, 'fill') => 'fill_blank',
            str_contains($t, 'rewrite'), str_contains($t, 'rephrase') => 'translation',
            str_contains($t, 'put the words'), str_contains($t, 'order') => 'sentence_reorder',
            str_contains($t, 'answer the questions') => 'reading_question',
            str_contains($t, 'write') => 'writing_task',
            default => 'fill_blank',
        };
    }

    private function difficultyFor(string $code): float
    {
        $level = CefrLevel::where('code', $code)->first();

        return $level ? round((float) $level->ability_min + ((float) $level->ability_max - (float) $level->ability_min) / 2, 3) : 0.0;
    }

    private function ordinal(string $code): int
    {
        return (int) (CefrLevel::where('code', $code)->value('ordinal') ?? 99);
    }

    private function cefrRange(string $band): array
    {
        $parts = explode('-', $band);

        return [trim($parts[0]), trim($parts[count($parts) - 1])];
    }

    private function recordStages(IngestionJob $job, array $counts): void
    {
        $stages = [
            [1, 'file_registration', 2], [2, 'text_extraction', $counts['lessons']],
            [3, 'structure_identification', $counts['units']], [4, 'semantic_segmentation', $counts['lessons']],
            [5, 'concept_extraction', $counts['concepts']], [6, 'exercise_extraction', $counts['exercises']],
            [7, 'answer_extraction', $counts['exercises']], [8, 'audio_mapping', $counts['audio']],
            [9, 'cefr_classification', $counts['units']], [10, 'difficulty_estimation', $counts['concepts']],
            [16, 'database_insertion', array_sum($counts)],
        ];
        foreach ($stages as [$n, $k, $total]) {
            IngestionStage::updateOrCreate(
                ['ingestion_job_id' => $job->id, 'stage_number' => $n],
                [
                    'stage_key' => $k, 'status' => 'completed',
                    'items_total' => $total, 'items_succeeded' => $total, 'items_failed' => 0,
                    'started_at' => now(), 'finished_at' => now(),
                ],
            );
        }
    }

    private function renderSummary(): void
    {
        $this->newLine();
        $rows = [];
        foreach ($this->stats as $book => $c) {
            $rows[] = [$book, $c['pages'], $c['units'], $c['lessons'], $c['segments'],
                       $c['vocab'], $c['senses'], $c['definitions'], $c['examples'],
                       $c['exercises'], $c['audio'], $c['images'], number_format($c['chars'])];
        }
        $totals = ['TOTAL'];
        for ($i = 1; $i <= 11; $i++) {
            $totals[] = array_sum(array_column($rows, $i));
        }
        $totals[] = number_format(array_sum(array_map(fn ($c) => $c['chars'], $this->stats)));
        $rows[] = $totals;
        $this->table(
            ['book', 'pages', 'units', 'lessons', 'segments', 'new vocab', 'senses',
             'defs', 'examples', 'exercises', 'audio', 'images', 'source chars'],
            $rows,
        );
    }
}
