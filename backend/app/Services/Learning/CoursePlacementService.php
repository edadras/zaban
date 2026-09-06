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
 *
 * The corpus has since grown from four books to sixteen, across six series -
 * vocabulary, grammar, pronunciation, phrasal verbs, collocations and idioms -
 * and each series runs its own ladder from elementary to advanced. Ordering
 * all of them together by level would hand a learner placed at B1 whichever
 * book happened to sort first at B1, and finishing it would move them sideways
 * into a different subject rather than up a level. So a ladder is always a
 * ladder within one series: vocabulary is the spine a learner is placed on and
 * promoted along, and the others are strands they can be given work from at
 * the level they are already at.
 */
class CoursePlacementService
{
    /**
     * The series a learner is placed on and promoted along.
     *
     * Vocabulary, because it is the series the platform was built around and
     * the only one that covers every level with a book of its own. The rest
     * are strands beside it - see `strandsForAbility`.
     */
    public const SPINE = 'vocabulary';

    /**
     * The course version a learner with this ability should be studying.
     *
     * Null only when no course is published at all.
     */
    public function versionForAbility(?float $ability, ?string $series = null): ?int
    {
        $courses = $this->ladder($series);

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
        // Up a level in the same subject. Walking the whole corpus by level
        // instead would move a learner who finished the elementary vocabulary
        // book sideways into elementary grammar and call it promotion.
        $ladder = $this->ladder($this->seriesOf($versionId))->values();
        $at = $ladder->search(fn ($c) => (int) $c->version_id === $versionId);

        if ($at === false) {
            return null;
        }

        return isset($ladder[$at + 1]) ? (int) $ladder[$at + 1]->version_id : null;
    }

    /**
     * Every strand a learner at this ability can work in, one course each.
     *
     * The spine is where they are placed and promoted; the other series are
     * offered at the level they have reached, because a B1 learner has a B1
     * grammar book and a B1 pronunciation book waiting for them and no reason
     * to work through the elementary ones first.
     *
     * @return array<string, int>  series => course version id
     */
    public function strandsForAbility(?float $ability): array
    {
        $out = [];
        foreach ($this->series() as $series) {
            $version = $this->versionForAbility($ability, $series);
            if ($version !== null) {
                $out[$series] = $version;
            }
        }

        return $out;
    }

    /** Which series a course version belongs to. */
    public function seriesOf(int $versionId): ?string
    {
        return DB::table('course_versions')
            ->join('courses', 'courses.id', '=', 'course_versions.course_id')
            ->where('course_versions.id', $versionId)
            ->value('courses.track');
    }

    /** The series that have a published course, the spine first. */
    public function series(): array
    {
        $tracks = DB::table('courses')
            ->join('course_versions', 'course_versions.course_id', '=', 'courses.id')
            ->where('courses.is_active', true)
            ->where('course_versions.status', 'published')
            ->distinct()
            ->pluck('courses.track')
            ->filter()
            ->all();

        sort($tracks);

        return array_values(array_unique(
            array_merge(
                in_array(self::SPINE, $tracks, true) ? [self::SPINE] : [],
                $tracks,
            ),
        ));
    }

    /**
     * The published course versions of one series, easiest first.
     *
     * Defaults to the spine. Passing a series that has no course falls back to
     * every course rather than to nothing, so a corpus that has not been
     * labelled by series still produces a ladder.
     */
    public function ladder(?string $series = null): Collection
    {
        $series ??= self::SPINE;

        $rungs = $this->rungs($series);

        return $rungs->isNotEmpty() ? $rungs : $this->rungs(null);
    }

    private function rungs(?string $series): Collection
    {
        $query = DB::table('course_versions')
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
                'courses.track as series',
                'lo.code as from_code',
                'hi.code as to_code',
                'lo.ability_min',
                'hi.ability_max as ability_max',
            ]);

        if ($series !== null) {
            $query->where('courses.track', $series);
        }

        return $query->get();
    }
}
