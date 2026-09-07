<?php

namespace App\Services\Classroom;

use App\Models\ClassGroup;
use App\Models\ClassScheduleRule;
use App\Models\ClassSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Turning "Tuesdays at six" into rows a learner can see on a calendar.
 *
 * Generated ahead rather than computed on read, because a session is a thing
 * that gets a title, an agenda, materials and a room - it has to exist before
 * the coach can prepare it. Sessions are only ever created in the future: the
 * past is what happened, and a rule edited today must not rewrite it.
 */
class ClassScheduleService
{
    /**
     * Fill in a group's calendar up to `$weeks` ahead.
     *
     * Idempotent: run it nightly and it adds only what is missing.
     *
     * @return int how many sessions were created
     */
    public function generate(ClassGroup $group, int $weeks = 8, ?CarbonImmutable $from = null): int
    {
        $from ??= CarbonImmutable::now($group->timezone);
        $until = $from->addWeeks($weeks);
        $created = 0;

        foreach ($group->rules()->where('is_active', true)->get() as $rule) {
            $created += $this->generateForRule($group, $rule, $from, $until);
        }

        return $created;
    }

    private function generateForRule(
        ClassGroup $group,
        ClassScheduleRule $rule,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): int {
        $tz = $group->timezone;
        $created = 0;

        $cursor = CarbonImmutable::parse($rule->starts_on, $tz)->startOfDay();
        if ($cursor->lt($from->startOfDay())) {
            $cursor = $from->startOfDay();
        }

        // Carbon's dayOfWeek is 0 = Sunday, which is the column's convention too.
        $delta = ($rule->weekday - $cursor->dayOfWeek + 7) % 7;
        $cursor = $cursor->addDays($delta);

        [$hour, $minute] = array_map('intval', explode(':', (string) $rule->start_time));

        while ($cursor->lte($until)) {
            if ($rule->ends_on !== null && $cursor->gt(CarbonImmutable::parse($rule->ends_on, $tz))) {
                break;
            }

            $starts = $cursor->setTime($hour, $minute);

            // Never behind the caller's clock: a rule added on Tuesday evening
            // must not conjure that morning's class.
            if ($starts->gt($from)) {
                $exists = ClassSession::where('class_group_id', $group->id)
                    ->where('schedule_rule_id', $rule->id)
                    ->where('starts_at', $starts->utc())
                    ->exists();

                if (! $exists) {
                    ClassSession::create([
                        'class_group_id' => $group->id,
                        'coach_id' => $group->coach_id,
                        'schedule_rule_id' => $rule->id,
                        'starts_at' => $starts->utc(),
                        'ends_at' => $starts->addMinutes($rule->duration_minutes)->utc(),
                        'status' => ClassSession::SCHEDULED,
                        'room_name' => self::roomName(),
                    ]);
                    $created++;
                }
            }

            $cursor = $cursor->addWeek();
        }

        return $created;
    }

    /**
     * An opaque room name.
     *
     * Not derived from the session id: a room name reaches the media server and
     * anyone who can read one must not be able to guess the next class's.
     */
    public static function roomName(): string
    {
        return 'cls_'.Str::lower(Str::random(24));
    }
}
