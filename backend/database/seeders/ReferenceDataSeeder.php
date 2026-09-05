<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\ExerciseTemplate;
use App\Models\Language;
use App\Models\PartOfSpeech;
use App\Models\Skill;
use App\Models\Subskill;
use Illuminate\Database\Seeder;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->languages();
        $this->cefrLevels();
        $this->skills();
        $this->partsOfSpeech();
        $this->exerciseTemplates();
    }

    private function languages(): void
    {
        $rows = [
            ['code' => 'en', 'name' => 'English',  'native_name' => 'English', 'direction' => 'ltr', 'is_learnable' => true,  'is_interface' => true],
            ['code' => 'fa', 'name' => 'Persian',  'native_name' => 'فارسی',   'direction' => 'rtl', 'is_learnable' => false, 'is_interface' => true],
            ['code' => 'tr', 'name' => 'Turkish',  'native_name' => 'Türkçe',  'direction' => 'ltr', 'is_learnable' => false, 'is_interface' => true],
            ['code' => 'ar', 'name' => 'Arabic',   'native_name' => 'العربية', 'direction' => 'rtl', 'is_learnable' => false, 'is_interface' => true],
            ['code' => 'es', 'name' => 'Spanish',  'native_name' => 'Español', 'direction' => 'ltr', 'is_learnable' => false, 'is_interface' => true],
            ['code' => 'ru', 'name' => 'Russian',  'native_name' => 'Русский', 'direction' => 'ltr', 'is_learnable' => false, 'is_interface' => true],
        ];
        foreach ($rows as $r) {
            Language::updateOrCreate(['code' => $r['code']], $r);
        }
    }

    /**
     * Ability bounds are on the logit scale shared by exercise difficulty, learner
     * ability and the CAT engine, so a level can be read straight off an estimate.
     */
    private function cefrLevels(): void
    {
        $rows = [
            ['code' => 'Pre-A1', 'ordinal' => 0, 'name' => 'Pre-A1 Beginner',      'ability_min' => -6.000, 'ability_max' => -2.500],
            ['code' => 'A1',     'ordinal' => 1, 'name' => 'A1 Breakthrough',      'ability_min' => -2.500, 'ability_max' => -1.500],
            ['code' => 'A2',     'ordinal' => 2, 'name' => 'A2 Waystage',          'ability_min' => -1.500, 'ability_max' => -0.500],
            ['code' => 'B1',     'ordinal' => 3, 'name' => 'B1 Threshold',         'ability_min' => -0.500, 'ability_max' =>  0.500],
            ['code' => 'B2',     'ordinal' => 4, 'name' => 'B2 Vantage',           'ability_min' =>  0.500, 'ability_max' =>  1.500],
            ['code' => 'C1',     'ordinal' => 5, 'name' => 'C1 Effective Operational Proficiency', 'ability_min' => 1.500, 'ability_max' => 2.500],
            ['code' => 'C2',     'ordinal' => 6, 'name' => 'C2 Mastery',           'ability_min' =>  2.500, 'ability_max' =>  6.000],
        ];
        foreach ($rows as $r) {
            CefrLevel::updateOrCreate(['code' => $r['code']], $r);
        }
    }

    private function skills(): void
    {
        $rows = [
            ['code' => 'vocabulary',    'name' => 'Vocabulary',    'is_productive' => false, 'position' => 1,
             'subskills' => ['breadth' => 'Breadth of knowledge', 'depth' => 'Depth of knowledge', 'collocation' => 'Collocation', 'word_formation' => 'Word formation']],
            ['code' => 'grammar',       'name' => 'Grammar',       'is_productive' => false, 'position' => 2,
             'subskills' => ['tense' => 'Tense and aspect', 'article' => 'Articles', 'preposition' => 'Prepositions', 'word_order' => 'Word order']],
            ['code' => 'reading',       'name' => 'Reading',       'is_productive' => false, 'position' => 3,
             'subskills' => ['gist' => 'Reading for gist', 'detail' => 'Reading for detail', 'inference' => 'Inference']],
            ['code' => 'listening',     'name' => 'Listening',     'is_productive' => false, 'position' => 4,
             'subskills' => ['gist' => 'Listening for gist', 'detail' => 'Listening for detail', 'discrimination' => 'Sound discrimination']],
            ['code' => 'speaking',      'name' => 'Speaking',      'is_productive' => true,  'position' => 5,
             'subskills' => ['fluency' => 'Fluency and coherence', 'interaction' => 'Interaction', 'range' => 'Lexical and grammatical range']],
            ['code' => 'writing',       'name' => 'Writing',       'is_productive' => true,  'position' => 6,
             'subskills' => ['task' => 'Task achievement', 'cohesion' => 'Cohesion', 'accuracy' => 'Accuracy']],
            ['code' => 'pronunciation', 'name' => 'Pronunciation', 'is_productive' => true,  'position' => 7,
             'subskills' => ['phoneme' => 'Phoneme accuracy', 'stress' => 'Word and sentence stress', 'intonation' => 'Intonation']],
        ];
        foreach ($rows as $r) {
            $subs = $r['subskills'];
            unset($r['subskills']);
            $skill = Skill::updateOrCreate(['code' => $r['code']], $r);
            $i = 0;
            foreach ($subs as $code => $name) {
                Subskill::updateOrCreate(
                    ['skill_id' => $skill->id, 'code' => $code],
                    ['name' => $name, 'position' => ++$i],
                );
            }
        }
    }

    private function partsOfSpeech(): void
    {
        $rows = [
            ['code' => 'noun', 'name' => 'Noun', 'abbreviation' => 'n.'],
            ['code' => 'verb', 'name' => 'Verb', 'abbreviation' => 'v.'],
            ['code' => 'adjective', 'name' => 'Adjective', 'abbreviation' => 'adj.'],
            ['code' => 'adverb', 'name' => 'Adverb', 'abbreviation' => 'adv.'],
            ['code' => 'preposition', 'name' => 'Preposition', 'abbreviation' => 'prep.'],
            ['code' => 'pronoun', 'name' => 'Pronoun', 'abbreviation' => 'pron.'],
            ['code' => 'determiner', 'name' => 'Determiner', 'abbreviation' => 'det.'],
            ['code' => 'conjunction', 'name' => 'Conjunction', 'abbreviation' => 'conj.'],
            ['code' => 'interjection', 'name' => 'Interjection', 'abbreviation' => 'interj.'],
            ['code' => 'phrase', 'name' => 'Phrase', 'abbreviation' => 'phr.'],
            ['code' => 'phrasal_verb', 'name' => 'Phrasal verb', 'abbreviation' => 'phr. v.'],
            ['code' => 'idiom', 'name' => 'Idiom', 'abbreviation' => 'idiom'],
        ];
        foreach ($rows as $r) {
            PartOfSpeech::updateOrCreate(['code' => $r['code']], $r);
        }
    }

    /**
     * The interactive block vocabulary from spec section 15. payload_schema is what
     * the generator must satisfy and what the validator checks generated items against.
     */
    private function exerciseTemplates(): void
    {
        $rows = [
            ['code' => 'multiple_choice', 'name' => 'Multiple choice', 'block_type' => 'multiple_choice',
             'skill_codes' => ['vocabulary', 'grammar', 'reading'], 'is_productive' => false,
             'payload_schema' => ['required' => ['stem', 'options'], 'min_options' => 3, 'exactly_one_correct' => true]],
            ['code' => 'fill_blank', 'name' => 'Fill the blank', 'block_type' => 'fill_the_blank',
             'skill_codes' => ['vocabulary', 'grammar'], 'is_productive' => false,
             'payload_schema' => ['required' => ['stem', 'blanks'], 'blank_marker' => '___']],
            ['code' => 'match', 'name' => 'Matching', 'block_type' => 'match',
             'skill_codes' => ['vocabulary'], 'is_productive' => false,
             'payload_schema' => ['required' => ['left', 'right'], 'min_pairs' => 3]],
            ['code' => 'sentence_reorder', 'name' => 'Sentence reorder', 'block_type' => 'sentence_reorder',
             'skill_codes' => ['grammar'], 'is_productive' => false,
             'payload_schema' => ['required' => ['tokens'], 'min_tokens' => 4]],
            ['code' => 'error_correction', 'name' => 'Error correction', 'block_type' => 'error_correction',
             'skill_codes' => ['grammar', 'vocabulary'], 'is_productive' => false,
             'payload_schema' => ['required' => ['stem', 'correction']]],
            ['code' => 'word_builder', 'name' => 'Word builder', 'block_type' => 'word_builder',
             'skill_codes' => ['vocabulary'], 'is_productive' => false,
             'payload_schema' => ['required' => ['stem', 'root']]],
            ['code' => 'flashcard', 'name' => 'Flashcard', 'block_type' => 'flashcard',
             'skill_codes' => ['vocabulary'], 'is_productive' => false,
             'payload_schema' => ['required' => ['front', 'back']]],
            ['code' => 'listen_and_choose', 'name' => 'Listen and choose', 'block_type' => 'listen_and_choose',
             'skill_codes' => ['listening'], 'supports_audio' => true, 'is_productive' => false,
             'payload_schema' => ['required' => ['audio', 'options']]],
            ['code' => 'listen_and_type', 'name' => 'Listen and type', 'block_type' => 'listen_and_type',
             'skill_codes' => ['listening'], 'supports_audio' => true, 'is_productive' => false,
             'payload_schema' => ['required' => ['audio', 'target_text']]],
            ['code' => 'dictation', 'name' => 'Dictation', 'block_type' => 'dictation',
             'skill_codes' => ['listening', 'writing'], 'supports_audio' => true, 'is_productive' => false,
             'payload_schema' => ['required' => ['audio', 'target_text']]],
            ['code' => 'minimal_pair', 'name' => 'Minimal pair', 'block_type' => 'minimal_pair',
             'skill_codes' => ['pronunciation', 'listening'], 'supports_audio' => true, 'is_productive' => false,
             'payload_schema' => ['required' => ['pair_a', 'pair_b']]],
            ['code' => 'repeat_after_speaker', 'name' => 'Repeat after speaker', 'block_type' => 'repeat_after_speaker',
             'skill_codes' => ['pronunciation', 'speaking'], 'supports_audio' => true, 'is_productive' => true,
             'payload_schema' => ['required' => ['target_text', 'reference_audio']]],
            ['code' => 'pronunciation_drill', 'name' => 'Pronunciation drill', 'block_type' => 'pronunciation_drill',
             'skill_codes' => ['pronunciation'], 'supports_audio' => true, 'is_productive' => true,
             'payload_schema' => ['required' => ['target_text', 'target_phonemes']]],
            ['code' => 'image_description', 'name' => 'Image description', 'block_type' => 'image_description',
             'skill_codes' => ['speaking', 'vocabulary'], 'supports_image' => true, 'is_productive' => true,
             'payload_schema' => ['required' => ['image', 'prompt']]],
            ['code' => 'open_speaking', 'name' => 'Open speaking', 'block_type' => 'open_speaking',
             'skill_codes' => ['speaking'], 'is_productive' => true,
             'payload_schema' => ['required' => ['prompt', 'rubric']]],
            ['code' => 'roleplay', 'name' => 'Roleplay', 'block_type' => 'roleplay',
             'skill_codes' => ['speaking'], 'is_productive' => true,
             'payload_schema' => ['required' => ['scenario', 'learner_role']]],
            ['code' => 'translation', 'name' => 'Translation', 'block_type' => 'translation',
             'skill_codes' => ['vocabulary', 'writing'], 'is_productive' => true,
             'payload_schema' => ['required' => ['source_text', 'target_language']]],
            ['code' => 'reading_question', 'name' => 'Reading question', 'block_type' => 'reading_question',
             'skill_codes' => ['reading'], 'is_productive' => false,
             'payload_schema' => ['required' => ['passage', 'stem']]],
            ['code' => 'writing_task', 'name' => 'Writing task', 'block_type' => 'writing_task',
             'skill_codes' => ['writing'], 'is_productive' => true,
             'payload_schema' => ['required' => ['prompt', 'rubric', 'min_words']]],
            ['code' => 'context_choice', 'name' => 'Context choice', 'block_type' => 'context_choice',
             'skill_codes' => ['vocabulary'], 'is_productive' => false,
             'payload_schema' => ['required' => ['context', 'options']]],
        ];
        foreach ($rows as $r) {
            $r += ['is_productive' => false, 'supports_audio' => false, 'supports_image' => false, 'is_active' => true];
            ExerciseTemplate::updateOrCreate(['code' => $r['code']], $r);
        }
    }
}
