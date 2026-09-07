<?php

namespace App\Console\Commands;

use App\Models\ClassSession;
use App\Services\Classroom\ClassNotifier;
use Illuminate\Console\Command;

/**
 * Tell learners about the class starting shortly.
 *
 * Run every minute. `notified_at` is what stops a class being announced sixty
 * times in the quarter of an hour before it starts.
 */
class NotifyUpcomingClasses extends Command
{
    protected $signature = 'classes:notify {--minutes= : how far ahead to look}';

    protected $description = 'Notify learners whose class is about to start';

    public function handle(ClassNotifier $notifier): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('live.notify_minutes_before'));

        $sessions = ClassSession::query()
            ->whereNull('notified_at')
            ->where('status', ClassSession::SCHEDULED)
            ->whereBetween('starts_at', [now(), now()->addMinutes($minutes)])
            ->with(['group.students', 'coach'])
            ->get();

        $told = 0;
        foreach ($sessions as $session) {
            $told += $notifier->announce($session);
        }

        $this->info("Classes announced: {$sessions->count()}, learners told: {$told}");

        return self::SUCCESS;
    }
}
