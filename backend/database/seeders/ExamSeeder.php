<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\ExamScoreBand;
use App\Models\ExamSection;
use App\Models\ExamTaskType;
use App\Models\ExamType;
use App\Models\ExerciseTemplate;
use App\Models\Language;
use App\Models\Skill;
use Illuminate\Database\Seeder;

/**
 * The four exam profiles as data (spec 31).
 *
 * Nothing in App\Services\Exam knows what IELTS is: sections, timings, question
 * types, rubric criteria, raw-to-scale conversion and the CEFR mapping all live
 * in these rows, so adding a fifth exam is a seeder change, not a code change.
 *
 * exam_sections.scoring_criteria is the contract ScoringService reads. Shape:
 *
 *   mode            objective | rubric
 *   aggregation     mean | sum   - how this exam combines section scores overall
 *   section_scale   {min,max,step} the section's own reported score
 *
 *   objective sections:
 *     raw_max       number of markable items
 *     conversion    table    - raw_min thresholds, the published IELTS tables
 *                   linear   - raw proportion mapped straight onto section_scale
 *                   anchors  - piecewise-linear between published (proportion,score) points
 *     table         rows for the chosen conversion
 *
 *   rubric sections:
 *     criterion_scale {min,max,step} the scale each criterion is judged on
 *     criteria        [{code,name,weight,descriptor}] - the JSON schema handed to the AI
 *     parts           speaking only: the interview structure and its clocks
 */
class ExamSeeder extends Seeder
{
    public function run(): void
    {
        $languageId = Language::where('code', 'en')->value('id');
        if (! $languageId) {
            throw new \RuntimeException('ExamSeeder needs reference data; run ReferenceDataSeeder first.');
        }

        $skills = Skill::pluck('id', 'code');
        $levels = CefrLevel::pluck('id', 'code');
        $templates = ExerciseTemplate::pluck('id', 'code');

        foreach ($this->profiles() as $profile) {
            $examType = ExamType::updateOrCreate(
                ['code' => $profile['code']],
                [
                    'language_id' => $languageId,
                    'name' => $profile['name'],
                    'description' => $profile['description'],
                    'score_type' => $profile['score_type'],
                    'score_min' => $profile['score_min'],
                    'score_max' => $profile['score_max'],
                    'score_step' => $profile['score_step'],
                    'total_minutes' => $profile['total_minutes'],
                    'is_active' => true,
                ],
            );

            foreach ($profile['bands'] as $band) {
                if (! isset($levels[$band['cefr']])) {
                    continue;
                }
                ExamScoreBand::updateOrCreate(
                    ['exam_type_id' => $examType->id, 'cefr_level_id' => $levels[$band['cefr']]],
                    ['score_from' => $band['from'], 'score_to' => $band['to']],
                );
            }

            foreach ($profile['sections'] as $position => $section) {
                if (! isset($skills[$section['skill']])) {
                    throw new \RuntimeException("Unknown skill code {$section['skill']} in exam {$profile['code']}.");
                }

                $row = ExamSection::updateOrCreate(
                    ['exam_type_id' => $examType->id, 'code' => $section['code']],
                    [
                        'skill_id' => $skills[$section['skill']],
                        'name' => $section['name'],
                        'position' => $position + 1,
                        'duration_minutes' => $section['duration_minutes'],
                        'question_count' => $section['question_count'] ?? null,
                        'scoring_criteria' => $section['scoring_criteria'],
                    ],
                );

                foreach ($section['task_types'] as $i => $taskType) {
                    ExamTaskType::updateOrCreate(
                        ['exam_section_id' => $row->id, 'code' => $taskType['code']],
                        [
                            'name' => $taskType['name'],
                            'description' => $taskType['description'] ?? null,
                            'exercise_template_id' => $templates[$taskType['template'] ?? ''] ?? null,
                            'typical_count' => $taskType['typical_count'] ?? null,
                        ],
                    );
                    unset($i);
                }
            }
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function profiles(): array
    {
        return [
            $this->ieltsAcademic(),
            $this->toeflIbt(),
            $this->cambridgeB2First(),
            $this->pteAcademic(),
        ];
    }

    // ------------------------------------------------------------ IELTS

    private function ieltsAcademic(): array
    {
        return [
            'code' => 'ielts_academic',
            'name' => 'IELTS Academic',
            'description' => 'International English Language Testing System, Academic module. Four papers reported '
                .'as half bands from 0 to 9; the overall band is the mean of the four, rounded to the nearest half band.',
            'score_type' => 'band',
            'score_min' => 0.0,
            'score_max' => 9.0,
            'score_step' => 0.5,
            'total_minutes' => 174,
            // Official IELTS-CEFR alignment, made contiguous so every score maps.
            'bands' => [
                ['cefr' => 'A1', 'from' => 0.00, 'to' => 2.50],
                ['cefr' => 'A2', 'from' => 2.51, 'to' => 3.50],
                ['cefr' => 'B1', 'from' => 3.51, 'to' => 5.00],
                ['cefr' => 'B2', 'from' => 5.01, 'to' => 6.50],
                ['cefr' => 'C1', 'from' => 6.51, 'to' => 8.00],
                ['cefr' => 'C2', 'from' => 8.01, 'to' => 9.00],
            ],
            'sections' => [
                [
                    'code' => 'listening',
                    'skill' => 'listening',
                    'name' => 'Listening',
                    'duration_minutes' => 40,
                    'question_count' => 40,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 0, 'max' => 9, 'step' => 0.5],
                        'raw_max' => 40,
                        'conversion' => 'table',
                        // Published IELTS Listening raw-score to band conversion.
                        'table' => [
                            ['raw_min' => 39, 'score' => 9.0], ['raw_min' => 37, 'score' => 8.5],
                            ['raw_min' => 35, 'score' => 8.0], ['raw_min' => 32, 'score' => 7.5],
                            ['raw_min' => 30, 'score' => 7.0], ['raw_min' => 26, 'score' => 6.5],
                            ['raw_min' => 23, 'score' => 6.0], ['raw_min' => 18, 'score' => 5.5],
                            ['raw_min' => 16, 'score' => 5.0], ['raw_min' => 13, 'score' => 4.5],
                            ['raw_min' => 10, 'score' => 4.0], ['raw_min' => 8, 'score' => 3.5],
                            ['raw_min' => 6, 'score' => 3.0], ['raw_min' => 4, 'score' => 2.5],
                            ['raw_min' => 3, 'score' => 2.0], ['raw_min' => 2, 'score' => 1.5],
                            ['raw_min' => 1, 'score' => 1.0], ['raw_min' => 0, 'score' => 0.0],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'form_completion', 'name' => 'Form completion', 'template' => 'fill_blank', 'typical_count' => 10,
                         'description' => 'Complete a form from a transactional conversation; word-limit rules apply.'],
                        ['code' => 'note_completion', 'name' => 'Note completion', 'template' => 'fill_blank', 'typical_count' => 10],
                        ['code' => 'table_completion', 'name' => 'Table completion', 'template' => 'fill_blank', 'typical_count' => 6],
                        ['code' => 'multiple_choice', 'name' => 'Multiple choice', 'template' => 'listen_and_choose', 'typical_count' => 6],
                        ['code' => 'matching', 'name' => 'Matching', 'template' => 'match', 'typical_count' => 5],
                        ['code' => 'plan_map_diagram_labelling', 'name' => 'Plan, map or diagram labelling', 'template' => 'match', 'typical_count' => 5,
                         'description' => 'Label a plan, map or diagram from a spoken description of a place or process.'],
                        ['code' => 'sentence_completion', 'name' => 'Sentence completion', 'template' => 'fill_blank', 'typical_count' => 5],
                        ['code' => 'short_answer', 'name' => 'Short-answer questions', 'template' => 'fill_blank', 'typical_count' => 5],
                    ],
                ],
                [
                    'code' => 'reading',
                    'skill' => 'reading',
                    'name' => 'Academic Reading',
                    'duration_minutes' => 60,
                    'question_count' => 40,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 0, 'max' => 9, 'step' => 0.5],
                        'raw_max' => 40,
                        'conversion' => 'table',
                        // Academic Reading is marked more severely than Listening at the
                        // same raw score; this is the published Academic table.
                        'table' => [
                            ['raw_min' => 39, 'score' => 9.0], ['raw_min' => 37, 'score' => 8.5],
                            ['raw_min' => 35, 'score' => 8.0], ['raw_min' => 33, 'score' => 7.5],
                            ['raw_min' => 30, 'score' => 7.0], ['raw_min' => 27, 'score' => 6.5],
                            ['raw_min' => 23, 'score' => 6.0], ['raw_min' => 19, 'score' => 5.5],
                            ['raw_min' => 15, 'score' => 5.0], ['raw_min' => 13, 'score' => 4.5],
                            ['raw_min' => 10, 'score' => 4.0], ['raw_min' => 8, 'score' => 3.5],
                            ['raw_min' => 6, 'score' => 3.0], ['raw_min' => 4, 'score' => 2.5],
                            ['raw_min' => 3, 'score' => 2.0], ['raw_min' => 2, 'score' => 1.5],
                            ['raw_min' => 1, 'score' => 1.0], ['raw_min' => 0, 'score' => 0.0],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'matching_headings', 'name' => 'Matching headings', 'template' => 'match', 'typical_count' => 6,
                         'description' => 'Match a heading to each paragraph; tests recognition of the main idea, not detail.'],
                        ['code' => 'true_false_not_given', 'name' => 'True / False / Not Given', 'template' => 'multiple_choice', 'typical_count' => 7,
                         'description' => 'Decide whether a statement agrees with factual information in the passage.'],
                        ['code' => 'yes_no_not_given', 'name' => 'Yes / No / Not Given', 'template' => 'multiple_choice', 'typical_count' => 6,
                         'description' => 'Decide whether a statement agrees with the writer\'s claims or views.'],
                        ['code' => 'matching_information', 'name' => 'Matching information', 'template' => 'match', 'typical_count' => 5],
                        ['code' => 'matching_features', 'name' => 'Matching features', 'template' => 'match', 'typical_count' => 5],
                        ['code' => 'sentence_completion', 'name' => 'Sentence completion', 'template' => 'fill_blank', 'typical_count' => 5],
                        ['code' => 'summary_completion', 'name' => 'Summary completion', 'template' => 'fill_blank', 'typical_count' => 6],
                        ['code' => 'multiple_choice', 'name' => 'Multiple choice', 'template' => 'reading_question', 'typical_count' => 5],
                        ['code' => 'diagram_label_completion', 'name' => 'Diagram label completion', 'template' => 'match', 'typical_count' => 4],
                        ['code' => 'short_answer', 'name' => 'Short-answer questions', 'template' => 'fill_blank', 'typical_count' => 4],
                    ],
                ],
                [
                    'code' => 'writing',
                    'skill' => 'writing',
                    'name' => 'Academic Writing',
                    'duration_minutes' => 60,
                    'question_count' => 2,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 0, 'max' => 9, 'step' => 0.5],
                        'criterion_scale' => ['min' => 0, 'max' => 9, 'step' => 0.5],
                        'criteria' => [
                            ['code' => 'task_achievement', 'name' => 'Task Achievement / Response', 'weight' => 0.25,
                             'descriptor' => 'How completely and appropriately the response addresses every part of the task, with a clear position and adequately developed, relevant support.'],
                            ['code' => 'coherence_and_cohesion', 'name' => 'Coherence and Cohesion', 'weight' => 0.25,
                             'descriptor' => 'Logical organisation and progression of ideas, paragraphing, and the accurate, unobtrusive use of cohesive devices and referencing.'],
                            ['code' => 'lexical_resource', 'name' => 'Lexical Resource', 'weight' => 0.25,
                             'descriptor' => 'Range, precision and flexibility of vocabulary, including collocation and word formation, and the effect of any errors on the reader.'],
                            ['code' => 'grammatical_range_and_accuracy', 'name' => 'Grammatical Range and Accuracy', 'weight' => 0.25,
                             'descriptor' => 'Range of sentence structures attempted and the accuracy of grammar and punctuation, judged by how far errors impede communication.'],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'task_1_report', 'name' => 'Task 1: data or process description', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'Describe, summarise or explain a graph, table, chart, diagram or process in at least 150 words. Recommended 20 minutes; counts one third of the section.'],
                        ['code' => 'task_2_essay', 'name' => 'Task 2: discursive essay', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'Write a discursive essay responding to a point of view, argument or problem in at least 250 words. Recommended 40 minutes; counts two thirds of the section.'],
                    ],
                ],
                [
                    'code' => 'speaking',
                    'skill' => 'speaking',
                    'name' => 'Speaking',
                    'duration_minutes' => 14,
                    'question_count' => 3,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 0, 'max' => 9, 'step' => 0.5],
                        'criterion_scale' => ['min' => 0, 'max' => 9, 'step' => 0.5],
                        'criteria' => [
                            ['code' => 'fluency_and_coherence', 'name' => 'Fluency and Coherence', 'weight' => 0.25,
                             'descriptor' => 'Speaking at length without noticeable effort, and connecting ideas coherently; hesitation for language rather than for content counts against this criterion.'],
                            ['code' => 'lexical_resource', 'name' => 'Lexical Resource', 'weight' => 0.25,
                             'descriptor' => 'Range and precision of vocabulary, idiomatic and less common items, and the ability to paraphrase when a word is missing.'],
                            ['code' => 'grammatical_range_and_accuracy', 'name' => 'Grammatical Range and Accuracy', 'weight' => 0.25,
                             'descriptor' => 'Variety of structures used and the accuracy with which they are produced, including complex forms and error density.'],
                            ['code' => 'pronunciation', 'name' => 'Pronunciation', 'weight' => 0.25,
                             'descriptor' => 'Intelligibility, phonological control of individual sounds, word and sentence stress, rhythm and intonation, and the effort required of the listener.'],
                        ],
                        // The interview structure the AI examiner runs; codes match the task types.
                        'parts' => [
                            ['code' => 'part_1_introduction', 'name' => 'Part 1: Introduction and interview',
                             'min_questions' => 4, 'max_questions' => 6, 'prep_seconds' => 0, 'response_seconds' => 60, 'duration_seconds' => 300],
                            ['code' => 'part_2_long_turn', 'name' => 'Part 2: Long turn',
                             'min_questions' => 1, 'max_questions' => 1, 'prep_seconds' => 60, 'response_seconds' => 120, 'duration_seconds' => 240],
                            ['code' => 'part_3_discussion', 'name' => 'Part 3: Two-way discussion',
                             'min_questions' => 4, 'max_questions' => 6, 'prep_seconds' => 0, 'response_seconds' => 90, 'duration_seconds' => 300],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'part_1_introduction', 'name' => 'Part 1: Introduction and interview', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Four to five minutes of familiar questions about home, work, study and interests.'],
                        ['code' => 'part_2_long_turn', 'name' => 'Part 2: Long turn', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'A cue card with one minute of preparation followed by one to two minutes of uninterrupted speech, then one or two rounding-off questions.'],
                        ['code' => 'part_3_discussion', 'name' => 'Part 3: Two-way discussion', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Four to five minutes of abstract discussion thematically linked to the Part 2 topic.'],
                    ],
                ],
            ],
        ];
    }

    // ------------------------------------------------------------ TOEFL

    private function toeflIbt(): array
    {
        return [
            'code' => 'toefl_ibt',
            'name' => 'TOEFL iBT',
            'description' => 'Test of English as a Foreign Language, internet-based test. Four sections scored 0 to 30 '
                .'and summed to a total out of 120.',
            'score_type' => 'scaled',
            'score_min' => 0.0,
            'score_max' => 120.0,
            'score_step' => 1.0,
            'total_minutes' => 116,
            // ETS-published CEFR alignment; TOEFL iBT does not reach C2.
            'bands' => [
                ['cefr' => 'A1', 'from' => 0, 'to' => 17],
                ['cefr' => 'A2', 'from' => 18, 'to' => 41],
                ['cefr' => 'B1', 'from' => 42, 'to' => 71],
                ['cefr' => 'B2', 'from' => 72, 'to' => 94],
                ['cefr' => 'C1', 'from' => 95, 'to' => 120],
            ],
            'sections' => [
                [
                    'code' => 'reading',
                    'skill' => 'reading',
                    'name' => 'Reading',
                    'duration_minutes' => 35,
                    'question_count' => 20,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'sum',
                        'section_scale' => ['min' => 0, 'max' => 30, 'step' => 1],
                        'raw_max' => 20,
                        'conversion' => 'linear',
                    ],
                    'task_types' => [
                        ['code' => 'factual_information', 'name' => 'Factual information', 'template' => 'reading_question', 'typical_count' => 3],
                        ['code' => 'negative_factual_information', 'name' => 'Negative factual information', 'template' => 'reading_question', 'typical_count' => 2,
                         'description' => 'Identify which of four statements is NOT stated in the passage.'],
                        ['code' => 'inference', 'name' => 'Inference', 'template' => 'reading_question', 'typical_count' => 2],
                        ['code' => 'rhetorical_purpose', 'name' => 'Rhetorical purpose', 'template' => 'reading_question', 'typical_count' => 2,
                         'description' => 'Explain why the author included a particular piece of information.'],
                        ['code' => 'vocabulary', 'name' => 'Vocabulary in context', 'template' => 'context_choice', 'typical_count' => 3],
                        ['code' => 'sentence_simplification', 'name' => 'Sentence simplification', 'template' => 'reading_question', 'typical_count' => 1],
                        ['code' => 'insert_text', 'name' => 'Insert text', 'template' => 'sentence_reorder', 'typical_count' => 1],
                        ['code' => 'prose_summary', 'name' => 'Prose summary', 'template' => 'match', 'typical_count' => 1,
                         'description' => 'Choose the three ideas that belong in a summary of the passage; worth two points.'],
                    ],
                ],
                [
                    'code' => 'listening',
                    'skill' => 'listening',
                    'name' => 'Listening',
                    'duration_minutes' => 36,
                    'question_count' => 28,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'sum',
                        'section_scale' => ['min' => 0, 'max' => 30, 'step' => 1],
                        'raw_max' => 28,
                        'conversion' => 'linear',
                    ],
                    'task_types' => [
                        ['code' => 'gist_content', 'name' => 'Gist-content', 'template' => 'listen_and_choose', 'typical_count' => 4],
                        ['code' => 'gist_purpose', 'name' => 'Gist-purpose', 'template' => 'listen_and_choose', 'typical_count' => 3],
                        ['code' => 'detail', 'name' => 'Detail', 'template' => 'listen_and_choose', 'typical_count' => 7],
                        ['code' => 'function', 'name' => 'Understanding the function of an utterance', 'template' => 'listen_and_choose', 'typical_count' => 4],
                        ['code' => 'attitude', 'name' => 'Understanding the speaker\'s attitude', 'template' => 'listen_and_choose', 'typical_count' => 3],
                        ['code' => 'organization', 'name' => 'Understanding organisation', 'template' => 'listen_and_choose', 'typical_count' => 3],
                        ['code' => 'connecting_content', 'name' => 'Connecting content', 'template' => 'match', 'typical_count' => 2],
                        ['code' => 'inference', 'name' => 'Making inferences', 'template' => 'listen_and_choose', 'typical_count' => 2],
                    ],
                ],
                [
                    'code' => 'speaking',
                    'skill' => 'speaking',
                    'name' => 'Speaking',
                    'duration_minutes' => 16,
                    'question_count' => 4,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'sum',
                        'section_scale' => ['min' => 0, 'max' => 30, 'step' => 1],
                        // ETS rates each response 0-4; the section total is a scaled conversion
                        // of the mean, which is what section_scale expresses here.
                        'criterion_scale' => ['min' => 0, 'max' => 4, 'step' => 0.5],
                        'criteria' => [
                            ['code' => 'delivery', 'name' => 'Delivery', 'weight' => 0.3333,
                             'descriptor' => 'Clear, fluid speech: pace, intelligibility, pronunciation and intonation, and how much listener effort is required.'],
                            ['code' => 'language_use', 'name' => 'Language Use', 'weight' => 0.3333,
                             'descriptor' => 'Effective, largely automatic control of grammar and vocabulary, with range appropriate to the task.'],
                            ['code' => 'topic_development', 'name' => 'Topic Development', 'weight' => 0.3334,
                             'descriptor' => 'Fully answering the task with coherent, well-connected and adequately developed ideas, and accurate use of the source material in integrated tasks.'],
                        ],
                        'parts' => [
                            ['code' => 'independent_choice', 'name' => 'Task 1: Independent speaking',
                             'min_questions' => 1, 'max_questions' => 1, 'prep_seconds' => 15, 'response_seconds' => 45, 'duration_seconds' => 60],
                            ['code' => 'integrated_campus', 'name' => 'Task 2: Campus situation',
                             'min_questions' => 1, 'max_questions' => 1, 'prep_seconds' => 30, 'response_seconds' => 60, 'duration_seconds' => 90],
                            ['code' => 'integrated_academic', 'name' => 'Task 3: Academic course concept',
                             'min_questions' => 1, 'max_questions' => 1, 'prep_seconds' => 30, 'response_seconds' => 60, 'duration_seconds' => 90],
                            ['code' => 'academic_summary', 'name' => 'Task 4: Academic lecture summary',
                             'min_questions' => 1, 'max_questions' => 1, 'prep_seconds' => 20, 'response_seconds' => 60, 'duration_seconds' => 80],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'independent_choice', 'name' => 'Independent speaking: paired choice', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => '15 seconds of preparation, 45 seconds of speech stating and supporting a personal preference.'],
                        ['code' => 'integrated_campus', 'name' => 'Integrated: campus situation', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Read a notice, hear a reaction to it, then report the speaker\'s opinion and reasons in 60 seconds.'],
                        ['code' => 'integrated_academic', 'name' => 'Integrated: academic course concept', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Read a definition, hear a lecture illustrating it, then explain the concept using the examples.'],
                        ['code' => 'academic_summary', 'name' => 'Integrated: lecture summary', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Summarise a short academic lecture using only the information heard.'],
                    ],
                ],
                [
                    'code' => 'writing',
                    'skill' => 'writing',
                    'name' => 'Writing',
                    'duration_minutes' => 29,
                    'question_count' => 2,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'sum',
                        'section_scale' => ['min' => 0, 'max' => 30, 'step' => 1],
                        'criterion_scale' => ['min' => 0, 'max' => 5, 'step' => 0.5],
                        'criteria' => [
                            ['code' => 'task_fulfilment', 'name' => 'Task Fulfilment', 'weight' => 0.4,
                             'descriptor' => 'Selecting the important information and, in the integrated task, relating it accurately to the reading passage without distortion or omission.'],
                            ['code' => 'organisation_and_development', 'name' => 'Organisation and Development', 'weight' => 0.3,
                             'descriptor' => 'Coherent progression, effective connection of ideas and adequate elaboration of the position taken.'],
                            ['code' => 'language_use', 'name' => 'Language Use', 'weight' => 0.3,
                             'descriptor' => 'Range and control of syntax and vocabulary; occasional minor error is tolerated, frequent error that obscures meaning is not.'],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'integrated_writing', 'name' => 'Integrated writing', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'Read a passage, hear a lecture that challenges it, then explain in 150-225 words how the lecture responds to the reading. 20 minutes.'],
                        ['code' => 'academic_discussion', 'name' => 'Writing for an academic discussion', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'Contribute to an online class discussion with a clear, well-supported position in at least 100 words. 10 minutes.'],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------- Cambridge

    private function cambridgeB2First(): array
    {
        // Cambridge reports on its own scale; the anchors below are the published
        // grade boundaries for B2 First expressed as proportions of the raw marks.
        $anchors = [
            ['proportion' => 0.00, 'score' => 122],
            ['proportion' => 0.30, 'score' => 132],
            ['proportion' => 0.45, 'score' => 140],
            ['proportion' => 0.60, 'score' => 160],
            ['proportion' => 0.75, 'score' => 173],
            ['proportion' => 0.80, 'score' => 180],
            ['proportion' => 1.00, 'score' => 190],
        ];

        return [
            'code' => 'cambridge_b2_first',
            'name' => 'Cambridge B2 First',
            'description' => 'Cambridge English Qualification at B2. Four papers reported on the Cambridge English '
                .'Scale from 122 to 190; the overall score is the mean of the four paper scores.',
            'score_type' => 'scaled',
            'score_min' => 122.0,
            'score_max' => 190.0,
            'score_step' => 1.0,
            'total_minutes' => 209,
            'bands' => [
                ['cefr' => 'A2', 'from' => 122, 'to' => 139],
                ['cefr' => 'B1', 'from' => 140, 'to' => 159],
                ['cefr' => 'B2', 'from' => 160, 'to' => 179],
                ['cefr' => 'C1', 'from' => 180, 'to' => 190],
            ],
            'sections' => [
                [
                    'code' => 'reading_use_of_english',
                    'skill' => 'reading',
                    'name' => 'Reading and Use of English',
                    'duration_minutes' => 75,
                    'question_count' => 52,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 122, 'max' => 190, 'step' => 1],
                        'raw_max' => 52,
                        'conversion' => 'anchors',
                        'table' => $anchors,
                    ],
                    'task_types' => [
                        ['code' => 'multiple_choice_cloze', 'name' => 'Part 1: Multiple-choice cloze', 'template' => 'context_choice', 'typical_count' => 8,
                         'description' => 'Eight gaps testing lexical precision, collocation, phrasal verbs and fixed phrases.'],
                        ['code' => 'open_cloze', 'name' => 'Part 2: Open cloze', 'template' => 'fill_blank', 'typical_count' => 8,
                         'description' => 'Eight gaps filled with one word each, testing grammatical and lexico-grammatical knowledge.'],
                        ['code' => 'word_formation', 'name' => 'Part 3: Word formation', 'template' => 'word_builder', 'typical_count' => 8],
                        ['code' => 'key_word_transformation', 'name' => 'Part 4: Key word transformation', 'template' => 'translation', 'typical_count' => 6,
                         'description' => 'Rewrite a sentence using a given key word without changing its meaning; two marks each.'],
                        ['code' => 'multiple_choice_reading', 'name' => 'Part 5: Multiple choice', 'template' => 'reading_question', 'typical_count' => 6],
                        ['code' => 'gapped_text', 'name' => 'Part 6: Gapped text', 'template' => 'sentence_reorder', 'typical_count' => 6,
                         'description' => 'Replace six removed sentences into a text; tests cohesion and text structure.'],
                        ['code' => 'multiple_matching', 'name' => 'Part 7: Multiple matching', 'template' => 'match', 'typical_count' => 10],
                    ],
                ],
                [
                    'code' => 'writing',
                    'skill' => 'writing',
                    'name' => 'Writing',
                    'duration_minutes' => 80,
                    'question_count' => 2,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 122, 'max' => 190, 'step' => 1],
                        'criterion_scale' => ['min' => 0, 'max' => 5, 'step' => 0.5],
                        'criteria' => [
                            ['code' => 'content', 'name' => 'Content', 'weight' => 0.25,
                             'descriptor' => 'Whether all content points are covered and the target reader is fully informed; irrelevance is penalised here.'],
                            ['code' => 'communicative_achievement', 'name' => 'Communicative Achievement', 'weight' => 0.25,
                             'descriptor' => 'Use of the conventions of the genre and register to hold the reader\'s attention and communicate straightforward and complex ideas.'],
                            ['code' => 'organisation', 'name' => 'Organisation', 'weight' => 0.25,
                             'descriptor' => 'Logical, well-ordered text using a variety of cohesive devices and organisational patterns to generally good effect.'],
                            ['code' => 'language', 'name' => 'Language', 'weight' => 0.25,
                             'descriptor' => 'Range and control of vocabulary and grammatical forms, including less common lexis and complex structures.'],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'part_1_essay', 'name' => 'Part 1: Compulsory essay', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'A 140-190 word discursive essay on a given topic with two supplied content points and one of the candidate\'s own.'],
                        ['code' => 'part_2_choice', 'name' => 'Part 2: Situationally based task', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'One of an article, email or letter, report or review, 140-190 words, chosen from three prompts.'],
                    ],
                ],
                [
                    'code' => 'listening',
                    'skill' => 'listening',
                    'name' => 'Listening',
                    'duration_minutes' => 40,
                    'question_count' => 30,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 122, 'max' => 190, 'step' => 1],
                        'raw_max' => 30,
                        'conversion' => 'anchors',
                        'table' => $anchors,
                    ],
                    'task_types' => [
                        ['code' => 'multiple_choice_short', 'name' => 'Part 1: Multiple choice (short extracts)', 'template' => 'listen_and_choose', 'typical_count' => 8],
                        ['code' => 'sentence_completion', 'name' => 'Part 2: Sentence completion', 'template' => 'fill_blank', 'typical_count' => 10],
                        ['code' => 'multiple_matching', 'name' => 'Part 3: Multiple matching', 'template' => 'match', 'typical_count' => 5],
                        ['code' => 'multiple_choice_long', 'name' => 'Part 4: Multiple choice (long text)', 'template' => 'listen_and_choose', 'typical_count' => 7],
                    ],
                ],
                [
                    'code' => 'speaking',
                    'skill' => 'speaking',
                    'name' => 'Speaking',
                    'duration_minutes' => 14,
                    'question_count' => 4,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 122, 'max' => 190, 'step' => 1],
                        'criterion_scale' => ['min' => 0, 'max' => 5, 'step' => 0.5],
                        'criteria' => [
                            ['code' => 'grammar_and_vocabulary', 'name' => 'Grammar and Vocabulary', 'weight' => 0.25,
                             'descriptor' => 'Range and control of simple and some complex grammatical forms, and appropriate vocabulary for familiar and unfamiliar topics.'],
                            ['code' => 'discourse_management', 'name' => 'Discourse Management', 'weight' => 0.25,
                             'descriptor' => 'Producing extended stretches of relevant, coherent language with very little hesitation and clear organisation.'],
                            ['code' => 'pronunciation', 'name' => 'Pronunciation', 'weight' => 0.25,
                             'descriptor' => 'Intelligibility, control of individual sounds, and appropriate use of stress and intonation to convey meaning.'],
                            ['code' => 'interactive_communication', 'name' => 'Interactive Communication', 'weight' => 0.25,
                             'descriptor' => 'Initiating and responding appropriately, maintaining and developing the interaction, and negotiating towards an outcome in the collaborative task.'],
                        ],
                        'parts' => [
                            ['code' => 'part_1_interview', 'name' => 'Part 1: Interview',
                             'min_questions' => 3, 'max_questions' => 5, 'prep_seconds' => 0, 'response_seconds' => 40, 'duration_seconds' => 120],
                            ['code' => 'part_2_long_turn', 'name' => 'Part 2: Long turn',
                             'min_questions' => 1, 'max_questions' => 2, 'prep_seconds' => 15, 'response_seconds' => 60, 'duration_seconds' => 240],
                            ['code' => 'part_3_collaborative_task', 'name' => 'Part 3: Collaborative task',
                             'min_questions' => 2, 'max_questions' => 3, 'prep_seconds' => 15, 'response_seconds' => 120, 'duration_seconds' => 240],
                            ['code' => 'part_4_discussion', 'name' => 'Part 4: Discussion',
                             'min_questions' => 3, 'max_questions' => 5, 'prep_seconds' => 0, 'response_seconds' => 60, 'duration_seconds' => 240],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'part_1_interview', 'name' => 'Part 1: Interview', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Two minutes of general questions about the candidate and their interests.'],
                        ['code' => 'part_2_long_turn', 'name' => 'Part 2: Long turn', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'One minute comparing two photographs and answering the printed question, then a short response to the partner\'s pictures.'],
                        ['code' => 'part_3_collaborative_task', 'name' => 'Part 3: Collaborative task', 'template' => 'roleplay', 'typical_count' => 1,
                         'description' => 'A two-way discussion around a spidergram of prompts, followed by a decision-making stage.'],
                        ['code' => 'part_4_discussion', 'name' => 'Part 4: Discussion', 'template' => 'open_speaking', 'typical_count' => 1,
                         'description' => 'Four minutes of further discussion of the Part 3 topic, extending to more abstract issues.'],
                    ],
                ],
            ],
        ];
    }

    // -------------------------------------------------------------- PTE

    private function pteAcademic(): array
    {
        // Pearson reports each communicative skill on the Global Scale of English
        // range 10-90. These anchors are the published GSE alignment points.
        $anchors = [
            ['proportion' => 0.00, 'score' => 10],
            ['proportion' => 0.25, 'score' => 30],
            ['proportion' => 0.45, 'score' => 43],
            ['proportion' => 0.65, 'score' => 59],
            ['proportion' => 0.82, 'score' => 76],
            ['proportion' => 0.92, 'score' => 85],
            ['proportion' => 1.00, 'score' => 90],
        ];

        return [
            'code' => 'pte_academic',
            'name' => 'PTE Academic',
            'description' => 'Pearson Test of English Academic. Reported on the Global Scale of English from 10 to 90. '
                .'The live test delivers speaking and writing as one timed part; they are timed separately here so each '
                .'skill can be practised and estimated on its own.',
            'score_type' => 'scaled',
            'score_min' => 10.0,
            'score_max' => 90.0,
            'score_step' => 1.0,
            'total_minutes' => 127,
            'bands' => [
                ['cefr' => 'A1', 'from' => 10, 'to' => 29],
                ['cefr' => 'A2', 'from' => 30, 'to' => 42],
                ['cefr' => 'B1', 'from' => 43, 'to' => 58],
                ['cefr' => 'B2', 'from' => 59, 'to' => 75],
                ['cefr' => 'C1', 'from' => 76, 'to' => 84],
                ['cefr' => 'C2', 'from' => 85, 'to' => 90],
            ],
            'sections' => [
                [
                    'code' => 'speaking',
                    'skill' => 'speaking',
                    'name' => 'Speaking',
                    'duration_minutes' => 35,
                    'question_count' => 5,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 10, 'max' => 90, 'step' => 1],
                        'criterion_scale' => ['min' => 10, 'max' => 90, 'step' => 1],
                        'criteria' => [
                            ['code' => 'oral_fluency', 'name' => 'Oral Fluency', 'weight' => 0.34,
                             'descriptor' => 'Smooth, effortless and natural-paced speech: no hesitation, false starts, repetition or unexpected pausing within a phrase.'],
                            ['code' => 'pronunciation', 'name' => 'Pronunciation', 'weight' => 0.33,
                             'descriptor' => 'Production of speech that is readily understandable to most regular speakers, with vowels and consonants, stress and intonation in the expected places.'],
                            ['code' => 'content', 'name' => 'Content', 'weight' => 0.33,
                             'descriptor' => 'Whether all the required material from the prompt is reproduced or covered, with no invented or omitted key information.'],
                        ],
                        'parts' => [
                            ['code' => 'read_aloud', 'name' => 'Read aloud',
                             'min_questions' => 5, 'max_questions' => 6, 'prep_seconds' => 35, 'response_seconds' => 40, 'duration_seconds' => 450],
                            ['code' => 'repeat_sentence', 'name' => 'Repeat sentence',
                             'min_questions' => 10, 'max_questions' => 12, 'prep_seconds' => 0, 'response_seconds' => 15, 'duration_seconds' => 300],
                            ['code' => 'describe_image', 'name' => 'Describe image',
                             'min_questions' => 3, 'max_questions' => 4, 'prep_seconds' => 25, 'response_seconds' => 40, 'duration_seconds' => 300],
                            ['code' => 're_tell_lecture', 'name' => 'Re-tell lecture',
                             'min_questions' => 1, 'max_questions' => 2, 'prep_seconds' => 10, 'response_seconds' => 40, 'duration_seconds' => 180],
                            ['code' => 'answer_short_question', 'name' => 'Answer short question',
                             'min_questions' => 5, 'max_questions' => 6, 'prep_seconds' => 0, 'response_seconds' => 10, 'duration_seconds' => 120],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'read_aloud', 'name' => 'Read aloud', 'template' => 'repeat_after_speaker', 'typical_count' => 6,
                         'description' => 'Read a text of up to 60 words aloud after 35 seconds of preparation.'],
                        ['code' => 'repeat_sentence', 'name' => 'Repeat sentence', 'template' => 'repeat_after_speaker', 'typical_count' => 11],
                        ['code' => 'describe_image', 'name' => 'Describe image', 'template' => 'image_description', 'typical_count' => 4],
                        ['code' => 're_tell_lecture', 'name' => 'Re-tell lecture', 'template' => 'open_speaking', 'typical_count' => 2],
                        ['code' => 'answer_short_question', 'name' => 'Answer short question', 'template' => 'open_speaking', 'typical_count' => 6],
                    ],
                ],
                [
                    'code' => 'writing',
                    'skill' => 'writing',
                    'name' => 'Writing',
                    'duration_minutes' => 25,
                    'question_count' => 3,
                    'scoring_criteria' => [
                        'mode' => 'rubric',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 10, 'max' => 90, 'step' => 1],
                        'criterion_scale' => ['min' => 10, 'max' => 90, 'step' => 1],
                        'criteria' => [
                            ['code' => 'content', 'name' => 'Content', 'weight' => 0.3,
                             'descriptor' => 'Whether the response addresses the prompt and, for summaries, captures the main points of the source without adding material.'],
                            ['code' => 'form_and_structure', 'name' => 'Form and Structure', 'weight' => 0.2,
                             'descriptor' => 'Compliance with the required length and format, and coherent development and linkage of ideas.'],
                            ['code' => 'grammar', 'name' => 'Grammar', 'weight' => 0.25,
                             'descriptor' => 'Correct grammatical structure with no errors that hinder communication.'],
                            ['code' => 'vocabulary_and_spelling', 'name' => 'Vocabulary and Spelling', 'weight' => 0.25,
                             'descriptor' => 'Appropriate and precise word choice with consistent, correct spelling in one variety of English.'],
                        ],
                    ],
                    'task_types' => [
                        ['code' => 'summarize_written_text', 'name' => 'Summarise written text', 'template' => 'writing_task', 'typical_count' => 2,
                         'description' => 'Condense a passage into one sentence of 5-75 words within 10 minutes.'],
                        ['code' => 'write_essay', 'name' => 'Write essay', 'template' => 'writing_task', 'typical_count' => 1,
                         'description' => 'A 200-300 word argumentative essay in 20 minutes.'],
                    ],
                ],
                [
                    'code' => 'reading',
                    'skill' => 'reading',
                    'name' => 'Reading',
                    'duration_minutes' => 30,
                    'question_count' => 20,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 10, 'max' => 90, 'step' => 1],
                        'raw_max' => 20,
                        'conversion' => 'anchors',
                        'table' => $anchors,
                    ],
                    'task_types' => [
                        ['code' => 'reading_writing_fill_blanks', 'name' => 'Reading and writing: fill in the blanks', 'template' => 'context_choice', 'typical_count' => 6],
                        ['code' => 'multiple_choice_multiple', 'name' => 'Multiple choice, multiple answers', 'template' => 'multiple_choice', 'typical_count' => 2,
                         'description' => 'Negatively marked: incorrect selections cancel correct ones.'],
                        ['code' => 're_order_paragraphs', 'name' => 'Re-order paragraphs', 'template' => 'sentence_reorder', 'typical_count' => 3],
                        ['code' => 'reading_fill_blanks', 'name' => 'Reading: fill in the blanks', 'template' => 'fill_blank', 'typical_count' => 5],
                        ['code' => 'multiple_choice_single', 'name' => 'Multiple choice, single answer', 'template' => 'reading_question', 'typical_count' => 4],
                    ],
                ],
                [
                    'code' => 'listening',
                    'skill' => 'listening',
                    'name' => 'Listening',
                    'duration_minutes' => 37,
                    'question_count' => 24,
                    'scoring_criteria' => [
                        'mode' => 'objective',
                        'aggregation' => 'mean',
                        'section_scale' => ['min' => 10, 'max' => 90, 'step' => 1],
                        'raw_max' => 24,
                        'conversion' => 'anchors',
                        'table' => $anchors,
                    ],
                    'task_types' => [
                        ['code' => 'summarize_spoken_text', 'name' => 'Summarise spoken text', 'template' => 'writing_task', 'typical_count' => 2],
                        ['code' => 'multiple_choice_multiple', 'name' => 'Multiple choice, multiple answers', 'template' => 'listen_and_choose', 'typical_count' => 2],
                        ['code' => 'fill_blanks', 'name' => 'Fill in the blanks', 'template' => 'listen_and_type', 'typical_count' => 3],
                        ['code' => 'highlight_correct_summary', 'name' => 'Highlight correct summary', 'template' => 'listen_and_choose', 'typical_count' => 2],
                        ['code' => 'multiple_choice_single', 'name' => 'Multiple choice, single answer', 'template' => 'listen_and_choose', 'typical_count' => 2],
                        ['code' => 'select_missing_word', 'name' => 'Select missing word', 'template' => 'listen_and_choose', 'typical_count' => 2],
                        ['code' => 'highlight_incorrect_words', 'name' => 'Highlight incorrect words', 'template' => 'error_correction', 'typical_count' => 2],
                        ['code' => 'write_from_dictation', 'name' => 'Write from dictation', 'template' => 'dictation', 'typical_count' => 4],
                    ],
                ],
            ],
        ];
    }
}
