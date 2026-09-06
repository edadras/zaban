<?php

namespace Tests\Feature\Content;

use App\Models\CefrLevel;
use App\Models\Language;
use App\Models\Translation;
use App\Models\VocabularyItem;
use App\Models\VocabularySense;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Reading the catalogue into the corpus.
 *
 * Two things about this table are easy to get wrong and invisible once wrong.
 *
 * A headword in this corpus has one sense per book that teaches it, and nothing
 * on the row says which meaning each one carries - so the meaning goes on all of
 * them, or a learner meeting "brother" in the idioms book is shown nothing while
 * the same word in the elementary book is glossed.
 *
 * And the unique index covers the text, so a corrected meaning inserted beside
 * the old one is a legal row: the word then has two translations, one of which
 * is the mistake that was being fixed.
 */
class TranslationImportTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    private Language $fa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);

        $this->fa = Language::where('code', 'fa')->firstOrFail();
        $this->path = base_path('../docs/data/translations/test.json');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    /** Write a catalogue the command will read, under a throwaway code. */
    private function catalogue(array $entries): void
    {
        Language::firstOrCreate(
            ['code' => 'test'],
            ['name' => 'Test', 'native_name' => 'Test', 'direction' => 'rtl', 'is_active' => false],
        );

        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        file_put_contents($this->path, json_encode($entries, JSON_UNESCAPED_UNICODE));
    }

    /** A headword taught in two places, as the corpus stores it. */
    private function twiceTaught(string $headword): VocabularyItem
    {
        $en = Language::where('code', 'en')->firstOrFail();
        $level = CefrLevel::where('code', 'A1')->firstOrFail();

        $item = VocabularyItem::create([
            'language_id' => $en->id,
            'headword' => $headword,
            'normalised' => mb_strtolower($headword),
            'cefr_level_id' => $level->id,
        ]);

        foreach ([1, 2] as $number) {
            VocabularySense::create([
                'vocabulary_item_id' => $item->id,
                'sense_number' => $number,
                'cefr_level_id' => $level->id,
            ]);
        }

        return $item;
    }

    public function test_the_meaning_reaches_every_sense_of_the_headword(): void
    {
        $item = $this->twiceTaught('brother');
        $this->catalogue(['brother' => 'برادر']);

        $this->artisan('content:translate --language=test')->assertSuccessful();

        $senses = $item->senses()->pluck('id');
        $this->assertCount(2, $senses);

        foreach ($senses as $senseId) {
            $this->assertDatabaseHas('translations', [
                'vocabulary_sense_id' => $senseId,
                'text' => 'برادر',
            ]);
        }
    }

    /** Case is the catalogue author's business, not the corpus's. */
    public function test_the_headword_is_matched_whatever_its_case(): void
    {
        $item = $this->twiceTaught('London');
        $this->catalogue(['london' => 'لندن']);

        $this->artisan('content:translate --language=test')->assertSuccessful();

        $this->assertSame(2, Translation::whereIn('vocabulary_sense_id', $item->senses()->pluck('id'))->count());
    }

    public function test_a_corrected_meaning_replaces_the_one_it_corrects(): void
    {
        $item = $this->twiceTaught('drive');

        $this->catalogue(['drive' => 'قهوه خوردن']);
        $this->artisan('content:translate --language=test')->assertSuccessful();

        $this->catalogue(['drive' => 'رانندگی کردن']);
        $this->artisan('content:translate --language=test')->assertSuccessful();

        $texts = Translation::whereIn('vocabulary_sense_id', $item->senses()->pluck('id'))
            ->pluck('text')->unique()->values()->all();

        $this->assertSame(['رانندگی کردن'], $texts);
    }

    /** An entry for a word nobody teaches is reported, not written. */
    public function test_a_headword_the_corpus_does_not_teach_is_reported(): void
    {
        $this->twiceTaught('brother');
        $this->catalogue(['brother' => 'برادر', 'antidisestablishmentarianism' => 'مخالفت با جدایی کلیسا از دولت']);

        $this->artisan('content:translate --language=test')
            ->expectsOutputToContain('in the catalogue but not in the corpus: 1')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('translations')->count());
    }

    /** One language's catalogue does not disturb another's. */
    public function test_fresh_removes_only_the_language_it_is_given(): void
    {
        $item = $this->twiceTaught('brother');
        $senseId = $item->senses()->value('id');

        Translation::create([
            'vocabulary_sense_id' => $senseId,
            'language_id' => $this->fa->id,
            'text' => 'برادر',
            'is_primary' => true,
        ]);

        $this->catalogue(['brother' => 'برادر']);
        $this->artisan('content:translate --language=test --fresh')->assertSuccessful();

        $this->assertSame(1, Translation::where('language_id', $this->fa->id)->count());
    }
}
