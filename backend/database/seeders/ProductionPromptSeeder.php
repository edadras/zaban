<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\ProductionPrompt;
use Illuminate\Database\Seeder;

/**
 * The things a learner is asked to produce.
 *
 * `production_prompts` held zero rows, which meant both halves of productive
 * language had nothing to set: the speech pipeline could score a recording and
 * the writing pipeline could mark an essay, but neither could ask for one. A
 * learner could only ever be assessed on what they recognised, never on what
 * they could say or write unprompted.
 *
 * The prompts below are graded so that each asks for what its level can
 * actually manage. A1 asks for sentences about a photograph the learner is
 * looking at; C1 asks them to argue a position they have to construct. Setting
 * a B2 task at A2 produces silence, and the reverse produces nothing worth
 * marking.
 *
 * Word counts are deliberately narrow at low levels and open at high ones -
 * "write 30 to 50 words" is a scaffold, "write 200 to 250" is a constraint.
 */
class ProductionPromptSeeder extends Seeder
{
    public function run(): void
    {
        $levels = CefrLevel::pluck('id', 'code');

        foreach ($this->prompts() as $p) {
            $levelId = $levels[$p['cefr']] ?? null;

            if (! $levelId) {
                continue;
            }

            ProductionPrompt::updateOrCreate(
                ['title' => $p['title'], 'modality' => $p['modality']],
                [
                    'language_id' => 1,
                    'task_type' => $p['task_type'],
                    'prompt' => $p['prompt'],
                    'guidance' => $p['guidance'],
                    'cefr_level_id' => $levelId,
                    'min_words' => $p['min_words'] ?? null,
                    'max_words' => $p['max_words'] ?? null,
                    'prep_seconds' => $p['prep_seconds'] ?? null,
                    'response_seconds' => $p['response_seconds'] ?? null,
                    'generation_method' => 'authored',
                ],
            );
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function prompts(): array
    {
        return [
            // ---- Written -------------------------------------------------
            [
                'modality' => 'written', 'task_type' => 'describe', 'cefr' => 'A1',
                'title' => 'Your room',
                'prompt' => 'Write about your room. What is in it? Where are the things?',
                'guidance' => 'Use there is and there are. Use words for furniture and places.',
                'min_words' => 25, 'max_words' => 50,
            ],
            [
                'modality' => 'written', 'task_type' => 'describe', 'cefr' => 'A1',
                'title' => 'Your day',
                'prompt' => 'Write about a normal day for you. What do you do in the morning, the afternoon and the evening?',
                'guidance' => 'Use the present simple. Use times of day.',
                'min_words' => 30, 'max_words' => 60,
            ],
            [
                'modality' => 'written', 'task_type' => 'message', 'cefr' => 'A2',
                'title' => 'A message to a friend',
                'prompt' => 'Write a short message to a friend inviting them to do something at the weekend. Say what, where and when, and ask if they can come.',
                'guidance' => 'Keep it informal. Remember to ask a question.',
                'min_words' => 40, 'max_words' => 70,
            ],
            [
                'modality' => 'written', 'task_type' => 'narrate', 'cefr' => 'A2',
                'title' => 'Last weekend',
                'prompt' => 'Write about what you did last weekend. Say where you went, who you were with, and whether you enjoyed it.',
                'guidance' => 'Use the past simple. Watch out for irregular verbs.',
                'min_words' => 50, 'max_words' => 90,
            ],
            [
                'modality' => 'written', 'task_type' => 'email', 'cefr' => 'B1',
                'title' => 'A complaint about an order',
                'prompt' => 'You ordered something online and it arrived damaged. Write an email to the shop. Explain what happened, say how it affected you, and say what you want them to do.',
                'guidance' => 'Be polite but firm. Organise it into a beginning, a middle and an end.',
                'min_words' => 100, 'max_words' => 150,
            ],
            [
                'modality' => 'written', 'task_type' => 'opinion', 'cefr' => 'B1',
                'title' => 'Living in a city or the countryside',
                'prompt' => 'Some people prefer living in a city; others prefer the countryside. Which do you prefer, and why? Give reasons and examples.',
                'guidance' => 'Say what you think in the first sentence, then support it. Use because, however and for example.',
                'min_words' => 120, 'max_words' => 180,
            ],
            [
                'modality' => 'written', 'task_type' => 'essay', 'cefr' => 'B2',
                'title' => 'Working from home',
                'prompt' => 'More people now work from home than ever before. What are the advantages and disadvantages of this for workers and for employers?',
                'guidance' => 'Balance both sides before reaching a conclusion. Signal each new point clearly.',
                'min_words' => 180, 'max_words' => 250,
            ],
            [
                'modality' => 'written', 'task_type' => 'report', 'cefr' => 'B2',
                'title' => 'A change in your town',
                'prompt' => 'Describe a change that has happened in your town or city in the last ten years. Explain why it happened and how people feel about it.',
                'guidance' => 'Use the present perfect for the change and the past simple for the causes.',
                'min_words' => 180, 'max_words' => 250,
            ],
            [
                'modality' => 'written', 'task_type' => 'argue', 'cefr' => 'C1',
                'title' => 'Technology and attention',
                'prompt' => 'It is often claimed that technology has damaged our ability to concentrate. How far do you agree? Argue a clear position and address the strongest objection to it.',
                'guidance' => 'A C1 answer does not sit on the fence. Take a position, then show you have understood what someone would say against it.',
                'min_words' => 220, 'max_words' => 300,
            ],
            [
                'modality' => 'written', 'task_type' => 'argue', 'cefr' => 'C2',
                'title' => 'Preserving a language',
                'prompt' => 'Is it worth spending public money to keep a language alive when very few people still speak it? Develop an argument that acknowledges what is genuinely lost and genuinely gained.',
                'guidance' => 'Precision and nuance matter more than length. Avoid stating the obvious at any point.',
                'min_words' => 250, 'max_words' => 350,
            ],

            // ---- Spoken --------------------------------------------------
            [
                'modality' => 'spoken', 'task_type' => 'describe', 'cefr' => 'A1',
                'title' => 'Say what you can see',
                'prompt' => 'Look at the picture and say what you can see. Name as many things as you can.',
                'guidance' => 'Single sentences are fine. Speak slowly and clearly.',
                'prep_seconds' => 20, 'response_seconds' => 45,
            ],
            [
                'modality' => 'spoken', 'task_type' => 'describe', 'cefr' => 'A2',
                'title' => 'Someone you know well',
                'prompt' => 'Talk about a person in your family. What do they look like? What are they like? What do they do?',
                'guidance' => 'Try to say three things about them, not one.',
                'prep_seconds' => 30, 'response_seconds' => 60,
            ],
            [
                'modality' => 'spoken', 'task_type' => 'narrate', 'cefr' => 'B1',
                'title' => 'A journey you remember',
                'prompt' => 'Describe a journey you remember well. Say where you went, how you travelled, and why it stayed with you.',
                'guidance' => 'Tell it in order. Do not stop to correct small mistakes - keep going.',
                'prep_seconds' => 60, 'response_seconds' => 120,
            ],
            [
                'modality' => 'spoken', 'task_type' => 'opinion', 'cefr' => 'B2',
                'title' => 'Learning at any age',
                'prompt' => 'Some people say it is too late to learn a new skill after a certain age. What do you think? Explain your reasoning.',
                'guidance' => 'Give a reason for each thing you claim. Two developed points beat five undeveloped ones.',
                'prep_seconds' => 60, 'response_seconds' => 150,
            ],
            [
                'modality' => 'spoken', 'task_type' => 'argue', 'cefr' => 'C1',
                'title' => 'A decision you would change',
                'prompt' => 'Describe a decision made in your country or your field that you believe was wrong. Explain what was decided, why it was wrong, and what should have happened instead.',
                'guidance' => 'Speak for the full time. Hesitation is fine; abandoning the argument halfway is not.',
                'prep_seconds' => 90, 'response_seconds' => 180,
            ],
        ];
    }
}
