<?php

namespace App\Console\Commands;

use App\Models\ClassGroup;
use App\Services\Classroom\ClassScheduleService;
use Illuminate\Console\Command;

/**
 * Keep every class's calendar filled a few weeks ahead.
 *
 * Run nightly. Idempotent, so running it twice costs nothing and missing a
 * night costs nothing either: the next run catches up.
 */
class GenerateClassSessions extends Command
{
    protected $signature = 'classes:generate {--weeks=8 : how far ahead to fill} {--group= : one class only}';

    protected $description = 'Create the class sessions the schedule rules imply';

    public function handle(ClassScheduleService $schedule): int
    {
        $groups = ClassGroup::query()
            ->where('is_active', true)
            ->when($this->option('group'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $created = 0;
        foreach ($groups as $group) {
            $made = $schedule->generate($group, (int) $this->option('weeks'));
            $created += $made;

            if ($made > 0) {
                $this->line("   {$group->title}: {$made}");
            }
        }

        $this->info("Sessions created: {$created} across {$groups->count()} class(es)");

        return self::SUCCESS;
    }
}
