<?php

namespace Tests\Feature\Classroom;

use App\Models\ClassSession;
use App\Models\MediaAsset;
use App\Services\Classroom\ClassroomService;
use App\Services\Classroom\RecordingService;
use App\Services\Live\LiveRoomProvider;
use App\Services\Live\NullRoomProvider;
use App\Services\Live\RecordingRequest;
use Illuminate\Support\Facades\Storage;

/**
 * Recording a class, and watching it back.
 *
 * The state is tested harder than the file, because the state is where this
 * goes wrong in a way a school only discovers a week later: a class that
 * believes it is being recorded and is not. So a recording that will not start
 * is a refusal the coach sees, and the webhook that says a file exists is
 * ignored unless the media server signed it.
 */
class RecordingTest extends ClassroomTestCase
{
    private ClassSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = $this->makeSession();
    }

    /** With nothing configured the coach is told, not shown a dead button. */
    public function test_recording_is_refused_when_the_installation_cannot_record(): void
    {
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/start");

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/record")
            ->assertStatus(422);

        $this->assertSame(ClassSession::RECORDING_OFF, $this->session->fresh()->recording_status);
    }

    public function test_the_room_says_whether_recording_is_available(): void
    {
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/start");

        $this->actingAs($this->coach)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/room")
            ->assertOk()
            ->assertJsonPath('data.recording.available', false)
            ->assertJsonPath('data.recording.status', ClassSession::RECORDING_OFF);
    }

    public function test_the_coach_starts_and_stops_a_recording(): void
    {
        $this->withRecorder();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/start");

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/record")
            ->assertCreated()
            ->assertJsonPath('data.status', ClassSession::RECORDING_ON);

        $this->assertNotNull($this->session->fresh()->recording_egress_id);

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/record/stop")
            ->assertOk()
            // Not ready: the media server needs a moment to write the file,
            // and says so with a webhook.
            ->assertJsonPath('data.status', ClassSession::RECORDING_PROCESSING);
    }

    /** A class that is not running has nothing to record. */
    public function test_a_class_that_has_not_started_cannot_be_recorded(): void
    {
        $this->withRecorder();

        $this->actingAs($this->coach)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/record")
            ->assertStatus(422);
    }

    public function test_a_learner_cannot_start_a_recording(): void
    {
        $this->withRecorder();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/start");

        $this->actingAs($this->student)
            ->postJson("/api/v1/class-sessions/{$this->session->id}/room/record")
            ->assertStatus(403);
    }

    /** Ending the class stops the recording before the room is torn down. */
    public function test_ending_the_class_stops_the_recording(): void
    {
        $provider = $this->withRecorder();
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/start");
        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/room/record");

        $this->actingAs($this->coach)->postJson("/api/v1/class-sessions/{$this->session->id}/end");

        $methods = array_column($provider->calls, 0);
        $stop = array_search('stopRecording', $methods, true);
        $delete = array_search('deleteRoom', $methods, true);

        $this->assertNotFalse($stop, 'the recording was stopped');
        $this->assertLessThan($delete, $stop, 'and stopped before the room was deleted');
        $this->assertSame(ClassSession::RECORDING_PROCESSING, $this->session->fresh()->recording_status);
    }

    // --------------------------------------------------------- the webhook

    /**
     * The one that matters.
     *
     * Without the signature check this endpoint is a way for anybody who
     * guesses an egress id to mark a class recorded and point it at a file of
     * their choosing.
     */
    public function test_an_unsigned_webhook_is_refused(): void
    {
        $this->recordingSession('eg_abc');

        $this->postJson('/api/v1/webhooks/live', [
            'event' => 'egress_ended',
            'egressInfo' => ['egressId' => 'eg_abc', 'status' => 'EGRESS_COMPLETE'],
        ])->assertStatus(403);

        $this->assertSame(ClassSession::RECORDING_ON, $this->session->fresh()->recording_status);
    }

    public function test_a_signed_webhook_attaches_the_file(): void
    {
        Storage::fake('recordings');
        Storage::disk('recordings')->put('class-1.mp4', 'not really a video');

        $this->recordingSession('eg_abc');

        $this->postSigned([
            'event' => 'egress_ended',
            'egressInfo' => [
                'egressId' => 'eg_abc',
                'status' => 'EGRESS_COMPLETE',
                'fileResults' => [[
                    'filename' => '/recordings/class-1.mp4',
                    'duration' => '5400000000000',   // nanoseconds
                    'size' => '18',
                ]],
            ],
        ])->assertOk();

        $session = $this->session->fresh();

        $this->assertSame(ClassSession::RECORDING_READY, $session->recording_status);
        $this->assertSame(5_400_000, $session->recording_duration_ms);
        $this->assertNotNull($session->recording_media_asset_id);

        $asset = MediaAsset::find($session->recording_media_asset_id);
        $this->assertSame('recordings', $asset->disk);
        $this->assertSame('class_recording', $asset->origin);
    }

    /** LiveKit retries; a second delivery must not make a second asset. */
    public function test_the_webhook_is_idempotent(): void
    {
        Storage::fake('recordings');
        Storage::disk('recordings')->put('class-1.mp4', 'not really a video');

        $this->recordingSession('eg_abc');

        $payload = [
            'event' => 'egress_ended',
            'egressInfo' => [
                'egressId' => 'eg_abc',
                'status' => 'EGRESS_COMPLETE',
                'fileResults' => [['filename' => '/recordings/class-1.mp4', 'size' => '18']],
            ],
        ];

        $this->postSigned($payload)->assertOk();
        $this->postSigned($payload)->assertOk();

        $this->assertSame(1, MediaAsset::where('origin', 'class_recording')->count());
    }

    public function test_a_failed_recording_says_why(): void
    {
        $this->recordingSession('eg_abc');

        $this->postSigned([
            'event' => 'egress_ended',
            'egressInfo' => [
                'egressId' => 'eg_abc',
                'status' => 'EGRESS_FAILED',
                'error' => 'no egress worker available',
            ],
        ])->assertOk();

        $session = $this->session->fresh();

        $this->assertSame(ClassSession::RECORDING_FAILED, $session->recording_status);
        $this->assertStringContainsString('egress worker', $session->recording_error);
        $this->assertNull($session->recording_media_asset_id);
    }

    // -------------------------------------------------------- watching back

    public function test_the_coach_the_school_and_the_class_may_watch(): void
    {
        $this->readyRecording();

        foreach ([$this->coach, $this->owner, $this->student] as $viewer) {
            $this->actingAs($viewer)
                ->getJson("/api/v1/class-sessions/{$this->session->id}/recording")
                ->assertOk()
                ->assertJsonPath('data.is_ready', true)
                ->assertJsonStructure(['data' => ['url', 'expires_in']]);
        }
    }

    /**
     * A learner who missed the class may still watch it.
     *
     * This is most of the point of recording one, so it is tested rather than
     * assumed: being on the roll is enough, having been in the room is not
     * required.
     */
    public function test_a_learner_who_missed_it_may_still_watch(): void
    {
        $this->readyRecording();

        $this->assertSame(
            0,
            $this->session->participants()->where('user_id', $this->otherStudent->id)->count(),
        );

        $this->actingAs($this->otherStudent)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/recording")
            ->assertOk();
    }

    public function test_somebody_from_another_school_cannot_watch(): void
    {
        $this->readyRecording();

        $this->actingAs($this->outsider)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/recording")
            ->assertStatus(403);
    }

    public function test_a_class_with_no_recording_says_so(): void
    {
        $this->actingAs($this->coach)
            ->getJson("/api/v1/class-sessions/{$this->session->id}/recording")
            ->assertStatus(404);
    }

    public function test_the_learners_history_shows_what_can_be_replayed(): void
    {
        $this->readyRecording();

        $this->actingAs($this->student)
            ->getJson('/api/v1/my/classes/history')
            ->assertOk()
            ->assertJsonPath('data.0.has_recording', true);
    }

    // ------------------------------------------------------------- helpers

    /** A provider that claims it can record, so the service takes the real path. */
    private function withRecorder(): NullRoomProvider
    {
        config(['live.recording.enabled' => true]);

        $provider = new class extends NullRoomProvider
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function canRecord(): bool
            {
                return true;
            }

            public function startRecording(RecordingRequest $request): ?string
            {
                $this->calls[] = ['startRecording', ['room' => $request->room]];

                return 'eg_test';
            }
        };

        $this->app->instance(LiveRoomProvider::class, $provider);
        $this->app->forgetInstance(RecordingService::class);
        $this->app->forgetInstance(ClassroomService::class);

        return $provider;
    }

    /** A class already recording under a known egress id. */
    private function recordingSession(string $egressId): void
    {
        $this->session->forceFill([
            'status' => ClassSession::LIVE,
            'recording_egress_id' => $egressId,
            'recording_status' => ClassSession::RECORDING_ON,
            'recording_started_at' => now(),
        ])->save();
    }

    private function readyRecording(): void
    {
        $asset = MediaAsset::create([
            'disk' => 'recordings',
            'path' => 'class-1.mp4',
            'type' => 'video',
            'mime' => 'video/mp4',
            'bytes' => 18,
            'origin' => 'class_recording',
            'copyright_status' => 'owned',
        ]);

        $this->session->forceFill([
            'status' => ClassSession::ENDED,
            'ended_at_actual' => now(),
            'recording_media_asset_id' => $asset->id,
            'recording_status' => ClassSession::RECORDING_READY,
            'recording_duration_ms' => 5_400_000,
        ])->save();
    }

    /** A webhook signed the way LiveKit signs one. */
    private function postSigned(array $payload)
    {
        config([
            'live.provider' => 'livekit',
            'live.livekit.key' => 'devkey',
            'live.livekit.secret' => 'devsecretdevsecretdevsecret',
            'live.livekit.ws_url' => 'wss://live.example.test',
            'live.livekit.http_url' => 'https://live.example.test',
        ]);
        $this->app->forgetInstance(LiveRoomProvider::class);

        $body = json_encode($payload);

        $encode = fn (array $part) => rtrim(strtr(base64_encode(
            json_encode($part, JSON_UNESCAPED_SLASHES)
        ), '+/', '-_'), '=');

        $signing = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => 'devkey',
            'exp' => time() + 300,
            'sha256' => base64_encode(hash('sha256', $body, true)),
        ]);

        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signing, 'devsecretdevsecretdevsecret', true)
        ), '+/', '-_'), '=');

        return $this->call(
            'POST',
            '/api/v1/webhooks/live',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => "{$signing}.{$signature}"],
            $body,
        );
    }
}
