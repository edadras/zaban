<?php

namespace Tests\Feature\Ai;

use App\AI\AiOrchestrator;
use App\AI\Support\TextRequest;
use App\Models\AiRequest;
use App\Models\AiUsage;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Ai\Support\BackupTextProvider;
use Tests\Feature\Ai\Support\FakeTextProvider;
use Tests\TestCase;

/**
 * The orchestrator is the only path to a vendor, so its bookkeeping has to hold:
 * the ledger must record every call, the cache must stop us paying twice, and a
 * failure must fall through to the next provider rather than surfacing.
 */
class OrchestratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
    }

    private function useProviders(array $providers, array $chain): void
    {
        $config = [];
        foreach ($providers as $code => $instance) {
            $class = $instance::class;
            $this->app->instance($class, $instance);
            $config[$code] = ['driver' => $class];
        }
        config(['ai.providers' => $config, 'ai.chains.text' => $chain]);
    }

    /**
     * Regression: the duration was computed as now()->diffInMilliseconds($start),
     * which Carbon 3 returns SIGNED - negative for a start in the past. Written
     * to the unsigned duration_ms column, that failed every AI call. This test
     * exercises the real write path so the bug cannot return silently.
     */
    public function test_a_successful_call_is_written_to_the_ledger_with_a_valid_duration(): void
    {
        $fake = new FakeTextProvider();
        $this->useProviders(['fake-text' => $fake], ['fake-text']);
        $user = User::factory()->create();

        $result = app(AiOrchestrator::class)->text(new TextRequest(
            feature: 'test.feature',
            prompt: 'hello world',
            userId: $user->id,
        ));

        $this->assertTrue($result->ok, 'orchestrator reported failure: '.($result->error ?? ''));

        $row = AiRequest::latest('id')->first();
        $this->assertNotNull($row, 'no ledger row was written');
        $this->assertSame('succeeded', $row->status);
        $this->assertNotNull($row->duration_ms, 'duration was not recorded');
        $this->assertGreaterThanOrEqual(0, $row->duration_ms,
            'duration must never be negative - the column is unsigned');
        $this->assertSame(120, $row->input_tokens);
        $this->assertSame(45, $row->output_tokens);

        $usage = AiUsage::where('user_id', $user->id)->first();
        $this->assertNotNull($usage, 'usage aggregate was not updated');
        $this->assertSame(1, $usage->request_count);
    }

    public function test_an_identical_request_is_served_from_cache_and_not_paid_for_twice(): void
    {
        $fake = new FakeTextProvider();
        $this->useProviders(['fake-text' => $fake], ['fake-text']);
        $orchestrator = app(AiOrchestrator::class);

        $make = fn () => new TextRequest(feature: 'test.cache', prompt: 'identical prompt');

        $first = $orchestrator->text($make());
        $second = $orchestrator->text($make());

        $this->assertTrue($first->ok);
        $this->assertTrue($second->ok);
        $this->assertSame(1, $fake->calls,
            'the second identical request reached the provider - the reuse cache is not working');
        $this->assertSame($first->text, $second->text);
    }

    public function test_a_failing_provider_falls_through_to_the_next_in_the_chain(): void
    {
        $broken = new FakeTextProvider(failWith: 'provider exploded');
        $working = new BackupTextProvider();

        $this->app->instance(FakeTextProvider::class, $broken);
        $this->app->instance(BackupTextProvider::class, $working);
        config([
            'ai.providers' => [
                'fake-text' => ['driver' => FakeTextProvider::class],
                'fake-backup' => ['driver' => BackupTextProvider::class],
            ],
            'ai.chains.text' => ['fake-text', 'fake-backup'],
        ]);

        $result = app(AiOrchestrator::class)->text(new TextRequest(
            feature: 'test.fallback', prompt: 'needs a backup',
        ));

        $this->assertTrue($result->ok, 'the chain did not fall through to the working provider');
        $this->assertSame(1, $broken->calls);
        $this->assertSame(1, $working->calls);

        // Both attempts must appear in the ledger - a silent fallback hides outages.
        $this->assertSame(1, AiRequest::where('status', 'failed')->count());
        $this->assertSame(1, AiRequest::where('status', 'succeeded')->count());
    }

    public function test_an_unavailable_provider_is_skipped_entirely(): void
    {
        $unavailable = new FakeTextProvider(available: false);
        $this->useProviders(['fake-text' => $unavailable], ['fake-text']);

        $result = app(AiOrchestrator::class)->text(new TextRequest(
            feature: 'test.unavailable', prompt: 'anything',
        ));

        $this->assertFalse($result->ok);
        $this->assertSame(0, $unavailable->calls);
        $this->assertSame(0, AiRequest::count(),
            'an unavailable provider should not produce a ledger row');
    }
}
