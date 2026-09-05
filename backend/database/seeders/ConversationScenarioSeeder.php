<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\ConversationScenario;
use App\Models\Language;
use Illuminate\Database\Seeder;

/** The roleplay settings listed in spec section 26. */
class ConversationScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $en = Language::where('code', 'en')->first();
        if (! $en) {
            return;
        }
        $levels = CefrLevel::pluck('id', 'code');

        $rows = [
            ['restaurant', 'Ordering a meal', 'restaurant', 'A2',
             'You are eating out and need to order, ask about the menu and pay.',
             'a customer', 'a friendly server taking the order',
             ['order a dish', 'ask about an ingredient', 'ask for the bill'], 10],
            ['airport-lost-luggage', 'Lost luggage', 'airport', 'B1',
             'You have arrived but your suitcase has not. You need to report it and arrange delivery.',
             'a passenger whose bag is missing', 'an airline desk agent',
             ['describe the bag', 'give a flight number', 'arrange delivery'], 12],
            ['hotel-check-in', 'Checking in', 'hotel', 'A2',
             'You are arriving at a hotel and need to check in and ask about facilities.',
             'a guest checking in', 'a receptionist',
             ['give your booking name', 'ask about breakfast', 'ask about wifi'], 10],
            ['doctor-appointment', 'At the doctor', 'doctor', 'B1',
             'You feel unwell and need to describe your symptoms and understand the advice.',
             'a patient', 'a general practitioner',
             ['describe symptoms', 'say how long it has lasted', 'ask about the treatment'], 12],
            ['shopping-return', 'Returning an item', 'shopping', 'A2',
             'Something you bought is faulty and you want a refund or exchange.',
             'a customer returning an item', 'a shop assistant',
             ['explain the problem', 'ask for a refund', 'show the receipt'], 10],
            ['job-interview', 'Job interview', 'job_interview', 'B2',
             'You are interviewing for a role and must present your experience convincingly.',
             'a candidate', 'a hiring manager',
             ['describe your experience', 'give a strength', 'ask about the team'], 14],
            ['team-meeting', 'Team meeting', 'meeting', 'B2',
             'You are in a project meeting and need to give an update and disagree politely.',
             'a team member', 'a project lead running the meeting',
             ['give a status update', 'raise a risk', 'disagree politely'], 14],
            ['catching-up', 'Catching up with a friend', 'friend_conversation', 'A2',
             'You have not seen a friend for a while and are catching up.',
             'yourself', 'an old friend',
             ['say what you have been doing', 'ask about their news', 'make a plan'], 12],
            ['booking-travel', 'Booking a trip', 'travel', 'B1',
             'You are arranging travel and need to compare options and book.',
             'a traveller', 'a travel agent',
             ['say where and when', 'compare two options', 'confirm the booking'], 12],
            ['phone-enquiry', 'Phone enquiry', 'phone_call', 'B1',
             'You are calling a company with a question and cannot see the person.',
             'a caller', 'a customer service agent',
             ['explain why you are calling', 'give your details', 'confirm what happens next'], 10],
            ['university-tutor', 'Talking to a tutor', 'university', 'B2',
             'You need an extension on an assignment and must explain and negotiate.',
             'a student', 'a university tutor',
             ['explain the situation', 'ask for an extension', 'agree a new date'], 12],
            ['exam-interview', 'Speaking exam interview', 'exam_interview', 'B2',
             'A formal spoken interview in the style of an international exam.',
             'a candidate', 'a speaking examiner',
             ['answer about yourself', 'speak at length on a topic', 'discuss an abstract question'], 15],
        ];

        foreach ($rows as [$slug, $title, $setting, $cefr, $situation, $learner, $ai, $objectives, $turns]) {
            ConversationScenario::updateOrCreate(
                ['slug' => $slug],
                [
                    'language_id' => $en->id,
                    'title' => $title,
                    'setting' => $setting,
                    'situation' => $situation,
                    'learner_role' => $learner,
                    'ai_role' => $ai,
                    'cefr_level_id' => $levels[$cefr] ?? null,
                    'objectives' => $objectives,
                    'target_turns' => $turns,
                ],
            );
        }
    }
}
