<?php

namespace App\Services\Scene;

use App\Models\Scene;
use App\Models\SceneAttempt;
use App\Models\SceneBeat;
use App\Models\SceneSession;
use App\Models\SpeechAttempt;
use App\Services\Learning\ProgressService;
use App\Services\Learning\RemediationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Runs an acted scene for one learner.
 *
 * The client draws the room and moves the mouths; what happens next is decided
 * here. That matters for more than tidiness: a scene that graded itself in the
 * browser would be a scene a learner could clear by editing a variable, and the
 * mastery the rest of the course records would be worth nothing.
 *
 * The shape of a run: the scene plays beat by beat until it reaches a line the
 * learner owns, and stops. They say it, choose it or complete it; a near-enough
 * answer moves the scene on, a wrong one is answered with the hint and another
 * go, and a third failure hands them the line so the scene never becomes a
 * locked door. Everything is remembered - what they said, how close it was, how
 * many goes it took - and the debrief at the end is built from that.
 */
class SceneDirector
{
    /** Tries before the line is simply given to them. */
    public const MAX_TRIES = 3;

    public function __construct(
        private LineMatcher $matcher,
        private RemediationService $remediation,
        private ProgressService $progress,
    ) {}

    /**
     * Open a run of a scene.
     *
     * `watch` plays the whole thing through with nothing asked. `guided` keeps
     * the scene's own interactive beats. `roleplay` promotes every line of the
     * chosen role to a spoken turn, which is the same scene at its hardest.
     */
    public function start(int $userId, Scene $scene, ?string $role = null, string $mode = 'guided'): SceneSession
    {
        $roles = $scene->roles();
        $role = $role !== null && in_array($role, $roles, true) ? $role : ($roles[1] ?? $roles[0] ?? null);

        // A learner who leaves mid-scene and comes back should land where they
        // were, not at the top with their answers thrown away.
        $existing = SceneSession::where('user_id', $userId)
            ->where('scene_id', $scene->id)
            ->where('status', 'active')
            ->where('role', $role)
            ->where('mode', $mode)
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        return SceneSession::create([
            'user_id' => $userId,
            'scene_id' => $scene->id,
            'role' => $role,
            'mode' => $mode,
            'status' => 'active',
            'position' => 0,
        ]);
    }

    /**
     * Which beats the learner has to produce in this run.
     *
     * @return Collection<int,SceneBeat>
     */
    public function learnerBeats(SceneSession $session, Scene $scene): Collection
    {
        return $scene->beats->filter(fn (SceneBeat $b) => $this->interactionFor($session, $b) !== 'watch')->values();
    }

    /**
     * What this beat asks of the learner in this run.
     *
     * A beat is only ever asked of the person playing that role: in the doctor's
     * scene the patient is never made to answer for the doctor.
     */
    public function interactionFor(SceneSession $session, SceneBeat $beat): string
    {
        if ($session->mode === 'watch') {
            return 'watch';
        }
        if ($session->role !== null && $beat->role !== $session->role) {
            return 'watch';
        }
        if ($session->mode === 'roleplay') {
            return $beat->interaction === 'choose' ? 'choose' : 'speak';
        }

        return $beat->interaction;
    }

    /**
     * Take the learner's go at one beat.
     *
     * @param  string|null  $given  what they said or typed
     * @param  int|null  $choice  index into the beat's choices
     * @return array{accepted:bool,similarity:int,tries:int,revealed:bool,missing:array,extra:array,feedback:string,line:?string}
     */
    public function answer(
        SceneSession $session,
        SceneBeat $beat,
        ?string $given = null,
        ?int $choice = null,
        ?SpeechAttempt $speech = null,
    ): array {
        $kind = $this->interactionFor($session, $beat);

        if ($kind === 'watch') {
            return $this->verdict(true, 100, 0, false, [], [], 'nothing_asked', $beat->text);
        }

        // A spoken turn is judged on what the recogniser heard. When the
        // transcript has not landed yet there is nothing to judge, and saying
        // "wrong" would be a lie about a recording nobody has read.
        if ($speech !== null) {
            $given = $speech->transcript ?: $given;
        }

        $tries = 1 + SceneAttempt::where('scene_session_id', $session->id)
            ->where('scene_beat_id', $beat->id)
            ->count();

        if ($kind === 'choose') {
            $result = $this->judgeChoice($beat, $choice);
        } else {
            $result = $this->matcher->match((string) $given, $beat->acceptedAnswers());
        }

        $revealed = ! $result['accepted'] && $tries >= self::MAX_TRIES;

        DB::transaction(function () use ($session, $beat, $kind, $given, $choice, $speech, $result, $tries) {
            SceneAttempt::create([
                'scene_session_id' => $session->id,
                'scene_beat_id' => $beat->id,
                'kind' => $kind,
                'given' => $kind === 'choose' ? $this->choiceText($beat, $choice) : $given,
                'speech_attempt_id' => $speech?->id,
                'similarity' => $result['similarity'],
                'accepted' => $result['accepted'],
                'try_number' => $tries,
                'detail' => [
                    'missing' => $result['missing'],
                    'extra' => $result['extra'],
                    'choice' => $choice,
                ],
            ]);

            $session->increment('attempts');

            if ($result['accepted']) {
                $session->increment('cleared');
            }
        });

        if ($result['accepted'] || $revealed) {
            $this->advancePast($session, $beat);
        }

        return $this->verdict(
            $result['accepted'],
            $result['similarity'],
            $tries,
            $revealed,
            $result['missing'],
            $result['extra'],
            $this->feedbackCode($result, $revealed, $tries),
            // The line is only handed over once it has been earned or given up on.
            $result['accepted'] || $revealed ? $beat->text : null,
        );
    }

    /** Close the run and build the debrief the learner actually reads. */
    public function finish(SceneSession $session): SceneSession
    {
        $session->loadMissing('scene.beats', 'sceneAttempts.beat');

        if ($session->status === 'completed') {
            return $session;
        }

        $asked = $this->learnerBeats($session, $session->scene);
        $attempts = $session->sceneAttempts;

        $clearedFirstTry = $attempts->where('accepted', true)->where('try_number', 1)->count();
        $cleared = $attempts->where('accepted', true)->pluck('scene_beat_id')->unique();
        $struggled = $asked->reject(fn (SceneBeat $b) => $cleared->contains($b->id));

        $score = $this->score($asked->count(), $cleared->count(), $clearedFirstTry, $attempts->count());

        // What they left out, remembered the same way every other mistake in the
        // course is, so a scene feeds the review queue like everything else.
        foreach ($attempts->where('accepted', false) as $attempt) {
            $missing = (array) ($attempt->detail['missing'] ?? []);
            if (! $missing) {
                continue;
            }
            $this->remediation->recordError(
                userId: $session->user_id,
                errorType: 'production',
                input: $attempt->given,
                expected: $attempt->beat?->text,
                subtype: 'scene_line',
                severity: $attempt->try_number >= self::MAX_TRIES ? 3 : 2,
                note: 'Missing in the spoken line: '.implode(', ', array_slice($missing, 0, 5)),
            );
        }

        $session->update([
            'status' => 'completed',
            'completed_at' => now(),
            'score' => $score,
            'summary' => [
                'lines_asked' => $asked->count(),
                'lines_cleared' => $cleared->count(),
                'first_try' => $clearedFirstTry,
                'attempts' => $attempts->count(),
                'went_well' => $this->strengths($asked->count(), $cleared->count(), $clearedFirstTry),
                'to_practise' => $struggled->take(3)->map(fn (SceneBeat $b) => [
                    'line' => $b->text,
                    'translation' => $b->translation_fa,
                    'hint' => $b->hint,
                ])->values()->all(),
                'vocabulary' => array_slice((array) ($session->scene->vocabulary ?? []), 0, 8),
            ],
        ]);

        $fresh = $session->fresh();
        $this->progress->recordSceneCompleted($fresh);

        return $fresh;
    }

    // ------------------------------------------------------------- internals

    private function judgeChoice(SceneBeat $beat, ?int $choice): array
    {
        $choices = array_values((array) ($beat->choices ?? []));
        $picked = $choice !== null ? ($choices[$choice] ?? null) : null;
        $correct = (bool) ($picked['correct'] ?? false);

        return [
            'similarity' => $correct ? 100 : 0,
            'accepted' => $correct,
            'close' => false,
            'matched' => $picked['text'] ?? null,
            'missing' => [],
            'extra' => [],
        ];
    }

    private function choiceText(SceneBeat $beat, ?int $choice): ?string
    {
        $choices = array_values((array) ($beat->choices ?? []));

        return $choice !== null ? ($choices[$choice]['text'] ?? null) : null;
    }

    /**
     * Move the run's marker past a beat that is done.
     *
     * Never backwards: replaying an earlier line to hear it again must not undo
     * progress the learner has already made.
     */
    private function advancePast(SceneSession $session, SceneBeat $beat): void
    {
        $next = (int) $beat->position + 1;
        if ($next > (int) $session->position) {
            $session->update(['position' => $next]);
        }
    }

    private function verdict(
        bool $accepted,
        int $similarity,
        int $tries,
        bool $revealed,
        array $missing,
        array $extra,
        string $feedback,
        ?string $line,
    ): array {
        return [
            'accepted' => $accepted,
            'similarity' => $similarity,
            'tries' => $tries,
            'tries_left' => max(0, self::MAX_TRIES - $tries),
            'revealed' => $revealed,
            'missing' => array_values($missing),
            'extra' => array_values($extra),
            'feedback' => $feedback,
            'line' => $line,
        ];
    }

    /**
     * A code rather than a sentence: the client writes the words, in the
     * learner's own language, and the server does not decide what language a
     * learner reads.
     */
    private function feedbackCode(array $result, bool $revealed, int $tries): string
    {
        if ($result['accepted']) {
            return $tries === 1 ? 'correct_first_try' : 'correct';
        }
        if ($revealed) {
            return 'revealed';
        }
        if (! empty($result['close'])) {
            return 'almost';
        }
        if (! empty($result['missing'])) {
            return 'missing_words';
        }

        return 'try_again';
    }

    private function score(int $asked, int $cleared, int $firstTry, int $attempts): float
    {
        if ($asked === 0) {
            // Watching a scene through is a real thing to have done, but it is
            // not a performance, and it is not scored as one.
            return 100.0;
        }

        $completion = $cleared / $asked;
        $fluency = $firstTry / $asked;
        $economy = $attempts > 0 ? min(1, $asked / $attempts) : 0;

        return round(100 * (0.6 * $completion + 0.25 * $fluency + 0.15 * $economy), 2);
    }

    private function strengths(int $asked, int $cleared, int $firstTry): array
    {
        $out = [];

        if ($asked > 0 && $cleared === $asked) {
            $out[] = 'You got through every line of your part.';
        }
        if ($firstTry > 0 && $firstTry === $asked) {
            $out[] = 'Every line first time, with no hints.';
        } elseif ($firstTry > 0) {
            $out[] = "You got {$firstTry} of {$asked} lines right first time.";
        }
        if ($asked === 0) {
            $out[] = 'You watched the whole scene through.';
        }

        return $out ?: ['You worked through the scene.'];
    }
}
