<?php

namespace Tests\Feature\Security;

use App\Jobs\Privacy\ProcessPrivacyRequests;
use App\Models\PrivacyRequest;
use App\Models\User;
use App\Services\Privacy\PrivacyRequestService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An export or an erasure someone asked for is a promise, and until now it was
 * only ever written down: `privacy_requests` rows were created by the profile
 * endpoints and nothing read them. These tests are about the promise being
 * kept - and about the two things erasure must not do.
 *
 * It must not take the accounting with it: an invoice has to survive a person
 * leaving, because a tax authority does not care that they asked. And it must
 * not leave the voice behind: a speech attempt deleted without its audio file
 * is a recording of somebody who believes they are gone.
 */
class PrivacyRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceDataSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
    }

    private function service(): PrivacyRequestService
    {
        return app(PrivacyRequestService::class);
    }

    private function learnerWithHistory(): User
    {
        $user = User::factory()->create(['name' => 'Sara', 'email' => 'sara@example.com']);

        DB::table('user_settings')->insert([
            'user_id' => $user->id, 'daily_target_minutes' => 20,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('daily_progress')->insert([
            'user_id' => $user->id, 'date' => now()->toDateString(),
            'study_seconds' => 600, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $user;
    }

    public function test_an_export_writes_the_learners_own_rows_to_a_private_file(): void
    {
        $user = $this->learnerWithHistory();
        $request = PrivacyRequest::create([
            'user_id' => $user->id, 'type' => 'export', 'status' => 'pending',
        ]);

        $done = $this->service()->process($request);

        $this->assertSame('completed', $done->status);
        $this->assertNotNull($done->export_path);
        Storage::disk('local')->assertExists($done->export_path);

        $payload = json_decode(
            Storage::disk('local')->get($done->export_path), true, 512, JSON_THROW_ON_ERROR,
        );
        $this->assertSame('sara@example.com', $payload['account']['email']);
        $this->assertCount(1, $payload['daily_progress']);
    }

    public function test_only_the_person_who_asked_can_download_their_export(): void
    {
        $user = $this->learnerWithHistory();
        $request = PrivacyRequest::create([
            'user_id' => $user->id, 'type' => 'export', 'status' => 'pending',
        ]);
        $this->service()->process($request);

        $this->actingAs($user)
            ->get("/api/v1/profile/privacy/{$request->id}/download")
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get("/api/v1/profile/privacy/{$request->id}/download")
            ->assertNotFound();
    }

    public function test_an_erasure_removes_the_learning_record_and_the_identity(): void
    {
        $user = $this->learnerWithHistory();
        $request = PrivacyRequest::create([
            'user_id' => $user->id, 'type' => 'delete_account', 'status' => 'pending',
        ]);

        $this->service()->process($request);

        $this->assertSame(0, DB::table('daily_progress')->where('user_id', $user->id)->count());
        $this->assertSame(0, DB::table('user_settings')->where('user_id', $user->id)->count());

        $row = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotNull($row, 'the row stays so the accounting stays balanced');
        $this->assertSame('Deleted account', $row->name);
        $this->assertStringEndsWith('@erased.invalid', $row->email);
        $this->assertNotNull($row->deleted_at);
    }

    public function test_an_erasure_keeps_the_invoices(): void
    {
        $user = $this->learnerWithHistory();
        DB::table('invoices')->insert([
            'user_id' => $user->id, 'number' => 'INV-1', 'status' => 'paid',
            'subtotal' => 100, 'discount_total' => 0, 'tax_total' => 0, 'total' => 100,
            'currency' => 'USD', 'issued_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = PrivacyRequest::create([
            'user_id' => $user->id, 'type' => 'delete_account', 'status' => 'pending',
        ]);
        $this->service()->process($request);

        $this->assertSame(1, DB::table('invoices')->where('user_id', $user->id)->count());
    }

    public function test_an_erasure_deletes_the_recording_as_well_as_its_row(): void
    {
        $user = $this->learnerWithHistory();
        Storage::disk('local')->put('speech/1/take.wav', 'audio');
        $assetId = DB::table('media_assets')->insertGetId([
            'disk' => 'local', 'path' => 'speech/1/take.wav', 'type' => 'audio',
            'mime' => 'audio/wav', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('speech_attempts')->insert([
            'user_id' => $user->id, 'media_asset_id' => $assetId,
            'expected_text' => 'hello', 'status' => 'scored',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $request = PrivacyRequest::create([
            'user_id' => $user->id, 'type' => 'delete_account', 'status' => 'pending',
        ]);
        $this->service()->process($request);

        Storage::disk('local')->assertMissing('speech/1/take.wav');
        $this->assertSame(0, DB::table('speech_attempts')->where('user_id', $user->id)->count());
    }

    /** A failed export must not stop the erasure queued behind it. */
    public function test_one_failing_request_does_not_hold_up_the_next(): void
    {
        $good = $this->learnerWithHistory();
        $broken = PrivacyRequest::create([
            'user_id' => $good->id, 'type' => 'nonsense', 'status' => 'pending',
        ]);
        $real = PrivacyRequest::create([
            'user_id' => $good->id, 'type' => 'export', 'status' => 'pending',
        ]);

        app(ProcessPrivacyRequests::class, ['limit' => 10])
            ->handle($this->service());

        $this->assertSame('failed', $broken->fresh()->status);
        $this->assertSame('completed', $real->fresh()->status);
    }

    public function test_an_export_stops_being_downloadable_once_its_window_closes(): void
    {
        $user = $this->learnerWithHistory();
        $request = PrivacyRequest::create([
            'user_id' => $user->id, 'type' => 'export', 'status' => 'pending',
        ]);
        $this->service()->process($request);
        $path = $request->fresh()->export_path;

        $request->fresh()->update(['expires_at' => now()->subDay()]);
        $this->assertSame(1, $this->service()->purgeExpiredExports());

        Storage::disk('local')->assertMissing($path);
        $this->assertSame('expired', $request->fresh()->status);

        $this->actingAs($user)
            ->getJson("/api/v1/profile/privacy/{$request->id}/download")
            ->assertNotFound();
    }

    public function test_the_learner_can_see_what_they_asked_for(): void
    {
        $user = $this->learnerWithHistory();

        $this->actingAs($user)->postJson('/api/v1/profile/export')->assertCreated();

        $rows = $this->actingAs($user)
            ->getJson('/api/v1/profile/privacy')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('export', $rows[0]['type']);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertFalse($rows[0]['downloadable']);
    }
}
