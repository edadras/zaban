<?php

namespace App\Services\Learning;

use App\Models\ConversationSession;
use App\Models\DailyProgress;
use App\Models\ExamAttempt;
use App\Models\ExerciseAttempt;
use App\Models\Language;
use App\Models\LearnerProfile;
use App\Models\LearningSession;
use App\Models\SpeechAttempt;
use App\Models\UserSetting;
use App\Models\XpTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes the learner-facing progress ledger: XP, study time, streak and the
 * daily_progress row the home dashboard reads.
 *
 * Schema and dashboard reads already existed; completion endpoints never called
 * anything here, so XP/time/streak stayed at zero. This is that missing writer.
 */
class ProgressService
{
    public const XP_EXERCISE_CORRECT = 10;

    public const XP_EXERCISE_ATTEMPT = 2;

    public const XP_SESSION_BASE = 25;

    public const XP_SESSION_PER_ACTIVITY = 5;

    public const XP_SESSION_PER_MINUTE = 2;

    public const XP_CONVERSATION_BASE = 20;

    public const XP_CONVERSATION_PER_TURN = 3;

    public const XP_EXAM_BASE = 50;

    public const XP_SPEECH_SCORED = 15;

    /** Record one graded exercise against today's counters and the XP ledger. */
    public function recordExerciseAttempt(ExerciseAttempt $attempt): void
    {
        DB::transaction(function () use ($attempt) {
            $daily = $this->today($attempt->user_id);
            $daily->exercises_attempted = (int) $daily->exercises_attempted + 1;
            if ($attempt->is_correct) {
                $daily->exercises_correct = (int) $daily->exercises_correct + 1;
            }
            $daily->save();

            $xp = $attempt->is_correct ? self::XP_EXERCISE_CORRECT : self::XP_EXERCISE_ATTEMPT;
            $this->awardXp(
                $attempt->user_id,
                $xp,
                $attempt->is_correct ? 'exercise_correct' : 'exercise_attempt',
                $attempt,
            );

            $this->touchStudyDay($attempt->user_id);
        });
    }

    /**
     * Close out a learning session: duration → study minutes, activities → XP,
     * and the streak clock. Safe to call again with a longer duration — XP is
     * awarded once; only the study-time delta is applied on repeats.
     */
    public function recordSessionCompleted(LearningSession $session): void
    {
        DB::transaction(function () use ($session) {
            $session->refresh();
            $seconds = max(0, (int) $session->actual_seconds);
            $credited = (int) data_get($session->composition, 'progress.study_seconds_credited', 0);

            if ($this->alreadyAwarded($session->user_id, $session, 'session_complete')) {
                $delta = max(0, $seconds - $credited);
                if ($delta > 0) {
                    $this->addStudySeconds($session->user_id, $delta);
                    $this->markStudySecondsCredited($session, $seconds);
                }

                return;
            }

            $activities = max(0, (int) $session->activities_completed);
            $minutes = (int) floor($seconds / 60);
            $xp = self::XP_SESSION_BASE
                + ($activities * self::XP_SESSION_PER_ACTIVITY)
                + ($minutes * self::XP_SESSION_PER_MINUTE);

            $session->xp_earned = $xp;
            $composition = is_array($session->composition) ? $session->composition : [];
            data_set($composition, 'progress.study_seconds_credited', $seconds);
            $session->composition = $composition;
            $session->save();

            $daily = $this->today($session->user_id);
            $daily->study_seconds = (int) $daily->study_seconds + $seconds;
            $daily->sessions_completed = (int) $daily->sessions_completed + 1;
            $daily->xp_earned = (int) $daily->xp_earned + $xp;
            $this->refreshGoalMet($daily, $session->user_id);
            $daily->save();

            $profile = $this->profile($session->user_id);
            $profile->total_study_minutes = (int) $profile->total_study_minutes
                + max(0, $minutes ?: ($seconds > 0 ? 1 : 0));
            $profile->last_session_at = now();
            $profile->save();

            $this->awardXp($session->user_id, $xp, 'session_complete', $session);
            $this->touchStudyDay($session->user_id);
        });
    }

    public function recordConversationCompleted(ConversationSession $session): void
    {
        if ($this->alreadyAwarded($session->user_id, $session, 'conversation_complete')) {
            return;
        }

        DB::transaction(function () use ($session) {
            $turns = max(0, (int) $session->turn_count);
            $xp = self::XP_CONVERSATION_BASE + ($turns * self::XP_CONVERSATION_PER_TURN);
            $seconds = max(60, $turns * 20);

            $this->addStudySeconds($session->user_id, $seconds, $xp);
            $this->awardXp($session->user_id, $xp, 'conversation_complete', $session);
            $this->touchStudyDay($session->user_id);
        });
    }

    public function recordExamCompleted(ExamAttempt $attempt): void
    {
        if ($this->alreadyAwarded($attempt->user_id, $attempt, 'exam_complete')) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $seconds = max(0, (int) ($attempt->duration_seconds ?? 0));
            if ($seconds === 0 && $attempt->started_at && $attempt->completed_at) {
                $seconds = max(0, $attempt->started_at->diffInSeconds($attempt->completed_at));
            }
            $seconds = max(60, $seconds);
            $score = (float) ($attempt->overall_score ?? 0);
            $xp = self::XP_EXAM_BASE + (int) round(min(50, max(0, $score)));

            $this->addStudySeconds($attempt->user_id, $seconds, $xp);
            $this->awardXp($attempt->user_id, $xp, 'exam_complete', $attempt);
            $this->touchStudyDay($attempt->user_id);
        });
    }

    public function recordSpeechScored(SpeechAttempt $attempt): void
    {
        if ($attempt->status !== 'scored') {
            return;
        }
        if ($this->alreadyAwarded($attempt->user_id, $attempt, 'speech_scored')) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $seconds = max(5, (int) ceil(((int) ($attempt->duration_ms ?? 0)) / 1000));
            $xp = self::XP_SPEECH_SCORED;

            $daily = $this->today($attempt->user_id);
            $daily->study_seconds = (int) $daily->study_seconds + $seconds;
            $daily->speaking_seconds = (int) $daily->speaking_seconds + $seconds;
            $daily->xp_earned = (int) $daily->xp_earned + $xp;
            $this->refreshGoalMet($daily, $attempt->user_id);
            $daily->save();

            $this->awardXp($attempt->user_id, $xp, 'speech_scored', $attempt);
            $this->touchStudyDay($attempt->user_id);
        });
    }

    public function awardXp(int $userId, int $amount, string $reason, ?Model $source = null): void
    {
        if ($amount <= 0) {
            return;
        }

        XpTransaction::create([
            'user_id' => $userId,
            'amount' => $amount,
            'reason' => $reason,
            'source_type' => $source ? $source->getMorphClass() : null,
            'source_id' => $source?->getKey(),
        ]);

        $profile = $this->profile($userId);
        $profile->xp = (int) $profile->xp + $amount;
        $profile->save();
    }

    /** Keep the streak continuous across calendar days of real study. */
    public function touchStudyDay(int $userId): void
    {
        $profile = $this->profile($userId);
        $today = now()->startOfDay();
        $last = $profile->last_study_date?->copy()?->startOfDay();

        if ($last && $last->equalTo($today)) {
            return;
        }

        if ($last && $last->equalTo($today->copy()->subDay())) {
            $profile->streak_days = (int) $profile->streak_days + 1;
        } else {
            $profile->streak_days = 1;
        }

        $profile->longest_streak_days = max(
            (int) $profile->longest_streak_days,
            (int) $profile->streak_days,
        );
        $profile->last_study_date = $today;
        $profile->save();
    }

    private function addStudySeconds(int $userId, int $seconds, int $xpToDaily = 0): void
    {
        $daily = $this->today($userId);
        $daily->study_seconds = (int) $daily->study_seconds + $seconds;
        if ($xpToDaily > 0) {
            $daily->xp_earned = (int) $daily->xp_earned + $xpToDaily;
        }
        $this->refreshGoalMet($daily, $userId);
        $daily->save();

        $minutes = (int) floor($seconds / 60);
        $profile = $this->profile($userId);
        $profile->total_study_minutes = (int) $profile->total_study_minutes
            + max(0, $minutes ?: ($seconds > 0 ? 1 : 0));
        $profile->last_session_at = now();
        $profile->save();
    }

    private function markStudySecondsCredited(LearningSession $session, int $seconds): void
    {
        $composition = is_array($session->composition) ? $session->composition : [];
        data_set($composition, 'progress.study_seconds_credited', $seconds);
        $session->update(['composition' => $composition]);
    }

    private function today(int $userId): DailyProgress
    {
        return DailyProgress::firstOrCreate(
            ['user_id' => $userId, 'date' => now()->toDateString()],
            [
                'study_seconds' => 0,
                'sessions_completed' => 0,
                'lessons_completed' => 0,
                'exercises_attempted' => 0,
                'exercises_correct' => 0,
                'reviews_completed' => 0,
                'new_concepts' => 0,
                'concepts_mastered' => 0,
                'speaking_seconds' => 0,
                'xp_earned' => 0,
                'goal_met' => false,
            ],
        );
    }

    private function profile(int $userId): LearnerProfile
    {
        return LearnerProfile::firstOrCreate(
            ['user_id' => $userId],
            [
                // Not nullable, and this is often the first thing to reach for a
                // profile - crediting an exam for someone who has not yet
                // started a lesson. Every other creator of a profile supplies
                // it; leaving it out here made the credit itself throw.
                'language_id' => Language::where('code', 'en')->value('id'),
                'xp' => 0,
                'streak_days' => 0,
                'longest_streak_days' => 0,
                'total_study_minutes' => 0,
                'ability' => 0,
                'placement_status' => 'not_started',
            ],
        );
    }

    private function refreshGoalMet(DailyProgress $daily, int $userId): void
    {
        $goalMinutes = (int) (UserSetting::where('user_id', $userId)->value('daily_target_minutes') ?? 15);
        $daily->goal_met = $goalMinutes > 0
            && ((int) $daily->study_seconds / 60) >= $goalMinutes;
    }

    private function alreadyAwarded(int $userId, Model $source, string $reason): bool
    {
        return XpTransaction::query()
            ->where('user_id', $userId)
            ->where('reason', $reason)
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->exists();
    }
}
