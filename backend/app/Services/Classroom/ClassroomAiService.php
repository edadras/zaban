<?php

namespace App\Services\Classroom;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\ClassThread;
use App\Models\ClassThreadReply;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The assistant, where a class can use one.
 *
 * Two rules run through all of it.
 *
 * It never speaks as a person. Everything it writes is stored with a flag that
 * the clients render as "the assistant said this, your coach has not looked at
 * it yet", because a learner has to be able to tell who is talking to them -
 * and because the coach's endorsement only means something if the draft was
 * visibly a draft.
 *
 * And it never blocks anybody. With no provider configured every method here
 * returns null and the class carries on exactly as it did: a person was going
 * to answer the question and mark the homework either way.
 */
class ClassroomAiService
{
    private const ANSWER_FEATURE = 'classroom.board.answer';

    public function __construct(private readonly AiOrchestrator $ai) {}

    public function boardAssistantEnabled(): bool
    {
        return (bool) config('classroom.ai.board_assistant');
    }

    /**
     * A first pass at a learner's question.
     *
     * Returns null when the assistant is off, when a person has already
     * answered - the point is to fill a silence, not to compete with the class
     * - or when no provider could be reached.
     */
    public function answerThread(ClassThread $thread): ?ClassThreadReply
    {
        if (! $this->boardAssistantEnabled() || $thread->kind !== ClassThread::QUESTION) {
            return null;
        }
        if ($thread->isHidden() || $thread->isLocked()) {
            return null;
        }
        if ($thread->replies()->exists()) {
            return null;
        }

        $assistant = $this->assistantAccount();

        if ($assistant === null) {
            return null;
        }

        $result = $this->ai->text(new TextRequest(
            feature: self::ANSWER_FEATURE,
            prompt: $this->questionPrompt($thread),
            system: $this->systemPrompt(),
            // Explaining a rule is not a place for invention.
            temperature: 0.2,
            maxTokens: 700,
            userId: (int) $thread->author_id,
            metadata: ['class_thread_id' => $thread->id],
            // One learner's own question in their own words: never served
            // from, nor added to, a shared cache.
            cacheable: false,
        ));

        if (! $result->ok || trim((string) $result->text) === '') {
            Log::info('board assistant did not answer', [
                'class_thread_id' => $thread->id,
                'error' => $result->error,
            ]);

            return null;
        }

        $reply = ClassThreadReply::create([
            'class_thread_id' => $thread->id,
            'author_id' => $assistant->id,
            'body' => trim((string) $result->text),
            'is_ai_answer' => true,
            'ai_endorsed' => false,
        ]);

        $thread->increment('reply_count');
        $thread->forceFill(['last_activity_at' => now()])->save();

        return $reply;
    }

    /**
     * The coach putting their name to the assistant's answer, or taking it
     * back. This is the only thing that turns a draft into an answer.
     */
    public function endorse(ClassThreadReply $reply, User $coach, bool $endorsed): ClassThreadReply
    {
        if (! $reply->is_ai_answer) {
            throw new ClassroomException('That answer was written by a person.');
        }

        $reply->update(['ai_endorsed' => $endorsed]);

        return $reply;
    }

    /**
     * The account the assistant writes as.
     *
     * A real row in `users` rather than a null author, so every reply has an
     * author and nothing downstream has to special-case a post nobody wrote.
     * It cannot be signed into: the password is never set to anything usable.
     */
    public function assistantAccount(): ?User
    {
        return User::firstOrCreate(
            ['email' => 'assistant@zaban.local'],
            [
                'name' => 'دستیار هوشمند',
                'password' => bcrypt(bin2hex(random_bytes(32))),
                'role' => 'learner',
                'status' => 'suspended',
                'timezone' => config('app.timezone'),
            ],
        );
    }

    // ------------------------------------------------------------- private

    private function systemPrompt(): string
    {
        $language = (string) config('classroom.ai.explain_in', 'Persian');

        return <<<PROMPT
        You are a teaching assistant on the message board of one English class.
        The people asking are learners of English; the coach who teaches them
        reads everything you write and will correct it.

        Answer in {$language}, and keep any English words, examples or
        sentences in English so the learner sees the real form.

        Rules:
        - Answer the question that was asked. Do not lecture.
        - Give one or two short examples. Learners remember examples.
        - If the question is ambiguous, say what you assumed.
        - If you are not sure, say so plainly and say what to ask the coach.
        - Never invent a rule to sound confident.
        - Do not do a learner's homework for them: explain how, then let them
          try. If the question is clearly a homework task copied in, explain
          the idea and say that the working is theirs to do.
        - At most 200 words.
        PROMPT;
    }

    private function questionPrompt(ClassThread $thread): string
    {
        $level = $thread->group?->level?->code;
        $body = trim((string) $thread->body);

        return implode("\n\n", array_filter([
            $level ? "The class is at CEFR level {$level}." : null,
            "Question title: {$thread->title}",
            $body !== '' ? "What they wrote:\n{$body}" : null,
        ]));
    }
}
