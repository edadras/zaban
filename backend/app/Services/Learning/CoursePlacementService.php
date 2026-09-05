<?php

namespace App\Services\Learning;

use App\Models\LearnerProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which book a learner is working through.
 *
 * The placement test was writing a CEFR level onto the profile and nothing was
 * reading it. `active_course_version_id` - the one field that chooses a book -
 * was never assigned by any code path, so it stayed null, and the curriculum
 * walked every lesson of all four books in id order. A learner placed at C1 and
 * a learner placed at A1 were both handed lesson one of the Elementary book.
 *
 * Placement now picks the course, and finishing one moves the learner up.
 */
class CoursePlacementService
{
    /**
     * The course version a learner with this ability should be studying.
     *
     * Null only when no course is published at all.
     */
    public function versionForAbility(?float $ability): ?int
    {
        $courses = $this->ladder();

        if ($courses->isEmpty()) {
            return null;
        }

        if ($ability === null) {
            return (int) $courses->first()->version_id;
        }

        foreach ($courses as $course) {
            if ($ability < (float) $course->ability_max) {
                return (int) $course->version_id;
            }
        }

        // Beyond the hardest book we have: the hardest book is still the answer.
        return (int) $courses->last()->version_id;
    }

    /**
     * Put a freshly placed learner into the right book.
     */
    public function assign(LearnerProfile $profile): ?int
    {
        $version = $this->versionForAbility(
            $profile->ability === null ? null : (float) $profile->ability,
        );

        if ($version !== null && $profile->active_course_version_id !== $version) {
            $profile->update(['active_course_version_id' => $version]);
        }

        return $version;
    }

    /**
     * The next course up, once this one has nothing left to teach.
     */
    public function nextVersionAfter(int $versionId): ?int
    {
        $ladder = $this->ladder()->values();
        $at = $ladder->search(fn ($c) => (int) $c->version_id === $versionId);

        if ($at === false) {
            return null;
        }

        return isset($ladder[$at + 1]) ? (int) $ladder[$at + 1]->version_id : null;
    }

    /** The published course versions, easiest first. */
    public function ladder(): Collection
    {
        return DB::table('course_versions')
            ->join('courses', 'courses.id', '=', 'course_versions.course_id')
            ->join('cefr_levels as lo', 'lo.id', '=', 'courses.from_cefr_level_id')
            ->join('cefr_levels as hi', 'hi.id', '=', 'courses.to_cefr_level_id')
            ->where('courses.is_active', true)
            ->where('course_versions.status', 'published')
            ->orderBy('lo.ordinal')
            ->select([
                'course_versions.id as version_id',
                'courses.id as course_id',
                'courses.slug',
                'courses.title',
                'lo.code as from_code',
                'hi.code as to_code',
                'lo.ability_min',
                'hi.ability_max as ability_max',
            ])
            ->get();
    }
}
