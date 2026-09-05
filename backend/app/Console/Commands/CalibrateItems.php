<?php

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Services\Learning\ItemCalibrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move the item bank from what we guessed to what learners showed us.
 *
 * Difficulty is seeded from surface features of the term, which is the best we
 * can do before anyone has answered anything. Once they have, the guess should
 * give way. Every attempt already stores the learner's ability at the moment it
 * was made, so the evidence has been accumulating; nothing was reading it.
 *
 * Run this on a schedule. It is idempotent, it will not move an item on thin
 * evidence, and it stands items down for review rather than quietly re-scoring
 * them when the pattern says the item is broken rather than hard.
 */
class CalibrateItems extends Command
{
    protected $signature = 'content:calibrate
        {--dry-run : report what would change without writing}
        {--min-attempts= : override the evidence threshold}';

    protected $description = 'Re-estimate item difficulty from real attempts, and flag items that look broken';

    public function handle(ItemCalibrator $calibrator): int
    {
        $minimum = (int) ($this->option('min-attempts') ?: ItemCalibrator::MIN_ATTEMPTS);
        $dry = (bool) $this->option('dry-run');

        $candidates = DB::table('exercise_attempts')
            ->whereNotNull('ability_at_attempt')
            ->groupBy('exercise_id')
            ->havingRaw('COUNT(*) >= ?', [$minimum])
            ->pluck('exercise_id');

        if ($candidates->isEmpty()) {
            $this->info("No item has {$minimum} attempts yet. Nothing to calibrate.");
            $this->line('Difficulty stays at its seeded estimate until learners have answered.');

            return self::SUCCESS;
        }

        $moved = 0;
        $suspect = [];
        $totalShift = 0.0;

        foreach ($candidates->chunk(200) as $chunk) {
            $exercises = Exercise::whereIn('id', $chunk->all())->get()->keyBy('id');

            $attempts = DB::table('exercise_attempts')
                ->whereIn('exercise_id', $chunk->all())
                ->whereNotNull('ability_at_attempt')
                ->select('exercise_id', 'ability_at_attempt', 'is_correct', 'response_ms')
                ->get()
                ->groupBy('exercise_id');

            foreach ($attempts as $exerciseId => $rows) {
                $exercise = $exercises->get($exerciseId);
                if (! $exercise) {
                    continue;
                }

                $result = $calibrator->calibrate(
                    $rows->map(fn ($r) => [
                        'ability' => (float) $r->ability_at_attempt,
                        'correct' => (bool) $r->is_correct,
                    ])->all(),
                    (float) $exercise->difficulty,
                    (float) ($exercise->discrimination ?: 1.0),
                    (float) $exercise->guessing,
                );

                if ($result === null) {
                    continue;
                }

                if ($result['suspect']) {
                    $suspect[] = [$exercise->id, $result['observed'], $result['expected'], $result['attempts']];
                }

                $responseMs = $rows->whereNotNull('response_ms')->avg('response_ms');

                if (! $dry) {
                    $exercise->update([
                        'difficulty' => $result['difficulty'],
                        'avg_response_ms' => $responseMs === null ? null : round((float) $responseMs, 2),
                        // A broken item must stop being served and stop being
                        // able to place anyone, without being deleted: a human
                        // needs to see what went wrong with it.
                        'status' => $result['suspect'] ? 'review' : $exercise->status,
                        'is_placement_eligible' => $result['suspect'] ? false : $exercise->is_placement_eligible,
                    ]);
                }

                if (abs($result['shift']) >= 0.05) {
                    $moved++;
                    $totalShift += abs($result['shift']);
                }
            }
        }

        $this->line('items with enough evidence : '.$candidates->count());
        $this->line('items whose estimate moved : '.$moved);
        if ($moved > 0) {
            $this->line('mean absolute shift        : '.round($totalShift / $moved, 3).' logits');
        }

        if ($suspect !== []) {
            $this->newLine();
            $this->warn(count($suspect).' item(s) look broken rather than hard, and were stood down for review:');
            $this->table(
                ['exercise', 'observed p', 'expected p', 'attempts'],
                array_slice($suspect, 0, 20),
            );
            $this->line('Learners well above these items are failing them at close to chance.');
            $this->line('The usual cause is a second option that is also correct.');
        }

        if ($dry) {
            $this->newLine();
            $this->comment('Dry run - nothing written.');
        }

        return self::SUCCESS;
    }
}
