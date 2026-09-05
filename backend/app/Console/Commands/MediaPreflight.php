<?php

namespace App\Console\Commands;

use App\Models\Character;
use App\Models\MediaBrief;
use Illuminate\Console\Command;

/**
 * Answers one question: is everything ready, so that buying the generation
 * window starts a render rather than starting the preparation?
 *
 * The window is time-boxed and its clock starts at purchase, not at readiness.
 * Anything this command reports as missing is work that would otherwise be done
 * on paid time for no reason.
 */
class MediaPreflight extends Command
{
    protected $signature = 'media:preflight';

    protected $description = 'Check whether the media backfill is ready to start';

    public function handle(): int
    {
        $checks = [
            $this->manifestBuilt(),
            $this->castDefined(),
            $this->promptsBound(),
            $this->importPathProven(),
        ];

        $this->table(
            ['check', 'state', 'detail'],
            array_map(fn ($c) => [$c['name'], $c['ok'] ? 'ready' : 'BLOCKED', $c['detail']], $checks),
        );

        $blocked = array_values(array_filter($checks, fn ($c) => ! $c['ok']));

        if ($blocked !== []) {
            $this->newLine();
            $this->error('Not ready. Do not buy the window yet:');

            foreach ($blocked as $c) {
                $this->line("  - {$c['name']}: {$c['fix']}");
            }

            return self::FAILURE;
        }

        $pending = MediaBrief::renderable()->count();

        $this->newLine();
        $this->info("Ready. {$pending} image(s) planned and waiting.");
        $this->line('The only thing still needed from outside is an account that can render them.');
        $this->newLine();
        $this->line('  php artisan media:manifest --limit=12 --claim   # next batch, provider-ready');
        $this->line('  php artisan media:import results.json            # results back in, linked');

        return self::SUCCESS;
    }

    private function manifestBuilt(): array
    {
        $pending = MediaBrief::renderable()->count();
        $total = MediaBrief::whereNot('status', MediaBrief::STATUS_SKIPPED)->count();
        $done = MediaBrief::where('status', MediaBrief::STATUS_IMPORTED)->count();

        return [
            'name' => 'Render manifest',
            'ok' => $total > 0,
            'detail' => "{$total} planned, {$done} done, {$pending} to render",
            'fix' => 'run php artisan media:briefs',
        ];
    }

    private function castDefined(): array
    {
        $cast = Character::count();
        $described = Character::whereNotNull('appearance_prompt')->count();

        return [
            'name' => 'Recurring cast',
            // Without a stable appearance the same character comes back as a
            // different person in every scene.
            'ok' => $cast > 0 && $described === $cast,
            'detail' => "{$cast} character(s), {$described} with a stable appearance",
            'fix' => 'run php artisan db:seed --class=CastSeeder',
        ];
    }

    private function promptsBound(): array
    {
        $model = (string) config('ai.providers.higgsfield.models.scene');
        $takesNegative = in_array($model, config('ai.providers.higgsfield.negative_prompt_models', []), true);

        // A negative left dangling against a model that ignores it means every
        // exclusion the content rules define is silently unenforced.
        $dangling = MediaBrief::whereNotNull('negative')
            ->whereNotIn('model', config('ai.providers.higgsfield.negative_prompt_models', []))
            ->count();

        return [
            'name' => 'Prompts bound to their model',
            'ok' => $dangling === 0,
            'detail' => $dangling === 0
                ? "scene model {$model}, exclusions ".($takesNegative ? 'passed separately' : 'folded into the prompt')
                : "{$dangling} brief(s) carry a negative their model ignores",
            'fix' => 'run php artisan media:briefs to rebuild the prompts',
        ];
    }

    private function importPathProven(): array
    {
        $imported = MediaBrief::where('status', MediaBrief::STATUS_IMPORTED)->count();

        return [
            'name' => 'Import path',
            // Proven by having actually round-tripped at least one generation,
            // not by the code merely existing.
            'ok' => $imported > 0,
            'detail' => $imported > 0
                ? "{$imported} generation(s) already stored and linked"
                : 'never exercised against a real generation',
            'fix' => 'import one real generation before committing to a bulk run',
        ];
    }
}
