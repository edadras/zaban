<?php

namespace Tests\Feature\Exam;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\AI\Support\TextResult;
use App\Models\ConversationSession;
use App\Models\ConversationTurn;
use App\Models\ExamScore;
use App\Models\SpeechAttempt;
use App\Services\Exam\AiExaminerService;
use App\Services\Exam\ExamService;
use Mockery;

/**
 * The IELTS speaking simulation: Part 1 questions, the Part 2 long turn with its
 * preparation minute, and the Part 3 discussion, ending in four criterion
 * estimates.
 */
class AiExaminerTest extends ExamTestCase
{
    /** @var TextRequest[] */
    private array $requests = [];

    private function setUpSpeaking(): void
    {
        $section = $this->section('ielts_academic', 'speaking');
        $this->task($section, 'part_1_introduction', 0, "Do you work or study?\nWhere do you live?\nDo you enjoy cooking?\nHow often do you travel?");
        $this->task($section, 'part_2_long_turn', 1, 'Describe a journey you remember well.');
        $this->task($section, 'part_3_discussion', 2, "Why do people travel?\nHas tourism changed your city?\nShould travel be cheaper?\nWhat will travel look like in fifty years?");
    }

    private function fakeExaminer(): void
    {
        $this->requests = [];

        $this->mock(AiOrchestrator::class, function ($mock) {
            $mock->shouldReceive('text')->andReturnUsing(function (TextRequest $request) {
                $this->requests[] = $request;

                if ($request->feature === AiExaminerService::FEATURE_QUESTIONS) {
                    return new TextResult(ok: true, json: ['questions' => [
                        'Tell me about where you live.',
                        'What do you like about it?',
                        'How has it changed recently?',
                        'Would you like to move somewhere else?',
                    ]]);
                }

                return new TextResult(ok: true, json: [
                    'criteria' => array_map(fn (string $code) => [
                        'code' => $code, 'score' => 6.5,
                        'rationale' => "Rationale for {$code}.", 'evidence' => ['a quoted phrase'],
                    ], [
                        'fluency_and_coherence', 'lexical_resource',
                        'grammatical_range_and_accuracy', 'pronunciation',
                    ]),
                    'errors' => [],
                    'summary' => 'A generally fluent performance.',
                ]);
            });
        });
    }

    private function recording(int $userId, string $transcript): SpeechAttempt
    {
        return SpeechAttempt::create([
            'user_id' => $userId,
            'transcript' => $transcript,
            'status' => 'scored',
            'duration_ms' => 42000,
            'pronunciation_score' => 78.5,
            'fluency_score' => 71.0,
            'speech_rate_wpm' => 132.4,
        ]);
    }

    public function test_the_interview_runs_part_1_then_the_long_turn_then_the_discussion(): void
    {
        $this->fakeExaminer();
        $this->setUpSpeaking();

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'speaking');
        $exams = app(ExamService::class);
        $examiner = app(AiExaminerService::class);

        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);

        $state = $examiner->interview($attempt);
        $this->assertFalse($state['complete']);
        $this->assertSame('part_1_introduction', $state['part']['code']);
        $this->assertSame(1, $state['part']['question_number']);
        $this->assertSame(4, $state['part']['question_total']);
        $this->assertSame(0, $state['prep_seconds']);
        $this->assertSame(60, $state['response_seconds']);
        $this->assertStringContainsString('not an official result', $state['estimate_notice']);

        for ($i = 0; $i < 4; $i++) {
            $state = $examiner->respond(
                $attempt->fresh(['sectionAttempts.section', 'examType']),
                $this->recording($user->id, "Part one answer number {$i}.")->id,
            );
        }

        // The long turn carries the published one-minute preparation and two-minute limit.
        $this->assertSame('part_2_long_turn', $state['part']['code']);
        $this->assertSame(60, $state['prep_seconds']);
        $this->assertSame(120, $state['response_seconds']);
        $this->assertSame(1, $state['part']['question_total']);

        $state = $examiner->respond(
            $attempt->fresh(['sectionAttempts.section', 'examType']),
            $this->recording($user->id, 'A long turn about a memorable journey across the country.')->id,
        );

        $this->assertSame('part_3_discussion', $state['part']['code']);
        $this->assertSame(90, $state['response_seconds']);

        for ($i = 0; $i < 4; $i++) {
            $state = $examiner->respond(
                $attempt->fresh(['sectionAttempts.section', 'examType']),
                $this->recording($user->id, "Part three answer number {$i}.")->id,
            );
        }

        $this->assertTrue($state['complete']);
    }

    public function test_each_completed_part_is_submitted_to_the_exam_with_its_recordings(): void
    {
        $this->fakeExaminer();
        $this->setUpSpeaking();

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'speaking');
        $exams = app(ExamService::class);
        $examiner = app(AiExaminerService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);

        $examiner->interview($attempt);
        for ($i = 0; $i < 4; $i++) {
            $examiner->respond($attempt->fresh(['sectionAttempts.section', 'examType']),
                $this->recording($user->id, "Answer {$i} about my home town.")->id);
        }

        $submitted = ExamScore::where('exam_attempt_id', $attempt->id)
            ->get()->filter(fn (ExamScore $s) => ExamService::isResponseCriterion($s->criterion));

        $this->assertCount(1, $submitted, 'part 1 closes as one submission');
        $evidence = $submitted->first()->evidence;
        $this->assertSame('speaking', $evidence['kind']);
        $this->assertCount(4, $evidence['speech_attempt_ids']);
        $this->assertCount(4, $evidence['prompt_questions']);
        // Measured acoustic signals travel with the response as evidence.
        $this->assertSame(78.5, $evidence['measured']['pronunciation_score']);
        $this->assertStringContainsString('Answer 0', $evidence['transcript']);
    }

    public function test_the_interview_is_kept_as_a_conversation_with_the_learner_recordings_attached(): void
    {
        $this->fakeExaminer();
        $this->setUpSpeaking();

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'speaking');
        $exams = app(ExamService::class);
        $examiner = app(AiExaminerService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);

        $examiner->interview($attempt);
        $examiner->respond($attempt->fresh(['sectionAttempts.section', 'examType']),
            $this->recording($user->id, 'I live in a small town near the coast.')->id);

        $session = ConversationSession::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($attempt->id, $session->objectives_met['exam']['exam_attempt_id']);

        $turns = ConversationTurn::where('conversation_session_id', $session->id)->orderBy('id')->get();
        $this->assertSame('ai', $turns[0]->speaker);
        $this->assertSame('learner', $turns[1]->speaker);
        $this->assertNotNull($turns[1]->speech_attempt_id);
    }

    public function test_the_examiner_falls_back_to_authored_questions_when_the_model_is_unavailable(): void
    {
        $this->mock(AiOrchestrator::class, function ($mock) {
            $mock->shouldReceive('text')->andReturn(TextResult::failure('provider unavailable'));
        });
        $this->setUpSpeaking();

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'speaking');
        $exams = app(ExamService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);

        $state = app(AiExaminerService::class)->interview($attempt);

        // The authored cue sheet is used verbatim; nothing is invented.
        $this->assertSame('Do you work or study?', $state['question']);
        $this->assertSame(4, $state['part']['question_total']);
    }

    public function test_speaking_is_scored_on_the_four_ielts_criteria_and_labelled_an_estimate(): void
    {
        $this->fakeExaminer();
        $this->setUpSpeaking();

        $user = $this->learner();
        $section = $this->section('ielts_academic', 'speaking');
        $exams = app(ExamService::class);
        $examiner = app(AiExaminerService::class);
        $attempt = $exams->start($user->id, $this->examType('ielts_academic'), ExamService::MODE_SECTION, $section->id);

        $examiner->interview($attempt);
        for ($i = 0; $i < 4; $i++) {
            $examiner->respond($attempt->fresh(['sectionAttempts.section', 'examType']),
                $this->recording($user->id, "Answer {$i} about my home town and my work.")->id);
        }

        $result = $examiner->score($attempt->fresh(['sectionAttempts.section', 'examType']));

        $this->assertEqualsCanonicalizing([
            'fluency_and_coherence', 'lexical_resource',
            'grammatical_range_and_accuracy', 'pronunciation',
        ], array_keys($result['criteria']));
        $this->assertSame(6.5, $result['estimated_score']);
        $this->assertTrue($result['is_estimate']);
        $this->assertFalse($result['is_official']);
        $this->assertTrue($result['is_ai_estimated']);

        // The rubric call carried the measured pronunciation signal as evidence.
        $rubric = collect($this->requests)->firstWhere('feature', \App\Services\Exam\ScoringService::FEATURE_RUBRIC);
        $this->assertNotNull($rubric);
        $this->assertStringContainsString('Measured speech signals', $rubric->prompt);
        $this->assertStringContainsString('Examiner questions asked', $rubric->prompt);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
