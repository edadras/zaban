<?php

namespace App\Console\Commands;

use App\Models\MediaBrief;
use Illuminate\Console\Command;

/**
 * Decide, in writing, that a brief should never be rendered.
 *
 * Reading a batch of prompts before paying for them turns up lessons no picture
 * can teach: shop signs, an on-screen order form, a printed menu - the artwork
 * would have to contain the words it teaches, and every brief forbids writing
 * in the image. Others have nothing to photograph at all: word-building, or a
 * list of nationalities where a picture could only be caricature.
 *
 * Recording that as a raw status update did not survive the next `media:briefs`,
 * which put all seven back in the queue. This locks the decision and keeps the
 * reason next to it, which is the part that matters when somebody later asks
 * why a lesson has no artwork.
 */
class SkipMediaBriefs extends Command
{
    protected $signature = 'media:skip
        {ids* : brief ids}
        {--reason= : why, in a few words; stored on the row and shown in the manifest}
        {--unskip : put them back in the queue instead}';

    protected $description = 'Decide that a brief is not worth rendering, so re-planning leaves it alone';

    public function handle(): int
    {
        $ids = array_map('intval', $this->argument('ids'));
        $briefs = MediaBrief::whereIn('id', $ids)->get();

        if ($briefs->isEmpty()) {
            $this->error('No such brief.');

            return self::FAILURE;
        }

        if ($this->option('unskip')) {
            MediaBrief::whereIn('id', $briefs->pluck('id'))->update([
                'status' => MediaBrief::STATUS_PENDING,
                'skip_reason' => null,
                'skip_locked' => false,
            ]);
            $this->info("{$briefs->count()} brief(s) back in the queue.");

            return self::SUCCESS;
        }

        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            // A skip with no reason is indistinguishable from a mistake, and
            // this one is permanent.
            $this->error('Say why: --reason="teaches written text; artwork may not contain writing".');

            return self::FAILURE;
        }

        $rendered = $briefs->whereIn('status', [MediaBrief::STATUS_GENERATED, MediaBrief::STATUS_IMPORTED]);

        if ($rendered->isNotEmpty()) {
            $this->warn("{$rendered->count()} of these are already rendered and were left alone.");
        }

        $toSkip = $briefs->diff($rendered);

        MediaBrief::whereIn('id', $toSkip->pluck('id'))->update([
            'status' => MediaBrief::STATUS_SKIPPED,
            'skip_reason' => mb_substr($reason, 0, 255),
            'skip_locked' => true,
        ]);

        $this->info("{$toSkip->count()} brief(s) will not be rendered: {$reason}");

        return self::SUCCESS;
    }
}
