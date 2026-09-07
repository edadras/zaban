<?php

namespace App\Services\Live;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * LiveKit, self-hosted.
 *
 * Chosen because it can be run on the same machine as the rest of the product:
 * no per-minute bill, no foreign card, and no third party holding a classroom's
 * audio. It also does server-side permission control, which is the one feature
 * this module cannot do without - "the coach mutes a learner" has to be a fact
 * the media server enforces, not a request the learner's app is asked politely
 * to honour.
 *
 * Two credentials and two protocols: a JWT the client presents to the SFU, and
 * the same JWT as a bearer against the Twirp admin API for the server-side
 * calls. Both are signed here with the API secret, which therefore never leaves
 * this class.
 */
class LiveKitRoomProvider implements LiveRoomProvider
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        /** wss:// for the client. */
        private readonly string $wsUrl,
        /** https:// for the admin API. Usually the same host. */
        private readonly string $httpUrl,
        private readonly int $tokenTtl = 21600,
    ) {}

    public function name(): string
    {
        return 'livekit';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->apiSecret !== '' && $this->wsUrl !== '';
    }

    public function createRoom(string $room, int $emptyTimeoutSeconds = 900, int $maxParticipants = 0): void
    {
        $this->admin('CreateRoom', [
            'name' => $room,
            'empty_timeout' => $emptyTimeoutSeconds,
            'max_participants' => $maxParticipants,
        ]);
    }

    public function deleteRoom(string $room): void
    {
        $this->admin('DeleteRoom', ['room' => $room]);
    }

    public function issueToken(string $room, RoomIdentity $identity, RoomPermissions $permissions): RoomToken
    {
        $now = time();

        $claims = [
            'iss' => $this->apiKey,
            'sub' => $identity->identity,
            'nbf' => $now - 10,
            'exp' => $now + $this->tokenTtl,
            'name' => $identity->name,
            'video' => array_filter([
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => $permissions->canPublish(),
                'canSubscribe' => $permissions->canSubscribe,
                'canPublishData' => $permissions->canPublishData,
                // Naming the sources is what makes "audio off, video on"
                // expressible; canPublish alone is all-or-nothing.
                'canPublishSources' => array_values(array_filter([
                    $permissions->canPublishAudio ? 'microphone' : null,
                    $permissions->canPublishVideo ? 'camera' : null,
                    $permissions->canShareScreen ? 'screen_share' : null,
                    $permissions->canShareScreen ? 'screen_share_audio' : null,
                ])),
                'roomAdmin' => $permissions->isModerator,
            ], fn ($v) => $v !== false && $v !== []),
        ];

        if ($identity->metadata !== []) {
            $claims['metadata'] = json_encode($identity->metadata, JSON_UNESCAPED_UNICODE);
        }

        return new RoomToken(
            token: $this->sign($claims),
            url: $this->wsUrl,
            room: $room,
            identity: $identity->identity,
            provider: $this->name(),
            expiresIn: $this->tokenTtl,
        );
    }

    public function updatePermissions(string $room, string $identity, RoomPermissions $permissions): void
    {
        $this->admin('UpdateParticipant', [
            'room' => $room,
            'identity' => $identity,
            'permission' => [
                'can_subscribe' => $permissions->canSubscribe,
                'can_publish' => $permissions->canPublish(),
                'can_publish_data' => $permissions->canPublishData,
                'can_publish_sources' => array_values(array_filter([
                    $permissions->canPublishAudio ? 'MICROPHONE' : null,
                    $permissions->canPublishVideo ? 'CAMERA' : null,
                    $permissions->canShareScreen ? 'SCREEN_SHARE' : null,
                ])),
            ],
        ]);
    }

    public function muteTrack(string $room, string $identity, string $kind, bool $muted): void
    {
        /*
         * Revoking permission stops the next track; this stops the one already
         * flowing. Both are needed: without the mute the learner keeps talking
         * until they republish, and without the permission change they simply
         * unmute themselves again.
         */
        $this->admin('MutePublishedTrack', [
            'room' => $room,
            'identity' => $identity,
            'track_sid' => '',
            'muted' => $muted,
            'kind' => $kind,
        ]);
    }

    public function removeParticipant(string $room, string $identity): void
    {
        $this->admin('RemoveParticipant', ['room' => $room, 'identity' => $identity]);
    }

    // --------------------------------------------------------------- egress

    /**
     * Egress is a second service behind the same API key.
     *
     * There is no cheap way to ask whether it is running without starting a
     * job, so this reports what the deployment claims rather than probing: a
     * recording that fails to start is reported to the coach when they press
     * record, which is the moment they can do something about it.
     */
    public function canRecord(): bool
    {
        return $this->isConfigured() && $this->httpUrl !== '';
    }

    public function startRecording(RecordingRequest $request): ?string
    {
        $output = $request->output === 's3'
            ? ['file' => [
                'filepath' => $request->filepath,
                's3' => array_filter([
                    'access_key' => $request->s3['access_key'] ?? '',
                    'secret' => $request->s3['secret'] ?? '',
                    'bucket' => $request->s3['bucket'] ?? '',
                    'region' => $request->s3['region'] ?? '',
                    'endpoint' => $request->s3['endpoint'] ?? '',
                ]),
            ]]
            : ['file' => ['filepath' => $request->filepath]];

        $response = $this->egress('StartRoomCompositeEgress', [
            'room_name' => $request->room,
            'layout' => $request->layout,
            'audio_only' => $request->audioOnly,
            // Old field and new both accepted by the server; sending the
            // repeated form is what current LiveKit expects.
            'file_outputs' => [$output['file']],
        ]);

        $egressId = $response['egressId'] ?? $response['egress_id'] ?? null;

        return is_string($egressId) && $egressId !== '' ? $egressId : null;
    }

    public function stopRecording(string $egressId): void
    {
        $this->egress('StopEgress', ['egress_id' => $egressId]);
    }

    /**
     * One call against the egress service.
     *
     * Unlike the room calls this one does not swallow a transport failure
     * silently: the caller turns a null into "recording could not be started"
     * and tells the coach, because a class that believes it is being recorded
     * and is not is worse than one that knows it is not.
     */
    private function egress(string $method, array $payload): ?array
    {
        if (! $this->canRecord()) {
            return null;
        }

        try {
            $response = $this->client(['roomRecord' => true])
                ->post("/twirp/livekit.Egress/{$method}", $payload);

            if ($response->failed()) {
                Log::warning('livekit egress call failed', [
                    'method' => $method,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('livekit egress unreachable', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Check that a webhook really came from our own media server.
     *
     * LiveKit signs the body: the bearer JWT carries a `sha256` claim holding
     * the digest of the exact bytes posted. Without this the endpoint is a way
     * for anyone who learns a session's egress id to mark a class recorded.
     */
    public function verifyWebhook(string $body, string $authorization): bool
    {
        $parts = explode('.', trim(str_ireplace('Bearer ', '', $authorization)));

        if (count($parts) !== 3) {
            return false;
        }

        [$header, $claims, $signature] = $parts;

        $expected = rtrim(strtr(base64_encode(
            hash_hmac('sha256', "{$header}.{$claims}", $this->apiSecret, true)
        ), '+/', '-_'), '=');

        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $payload = json_decode(base64_decode(strtr($claims, '-_', '+/')) ?: '[]', true);

        if (! is_array($payload) || ($payload['iss'] ?? null) !== $this->apiKey) {
            return false;
        }
        if (isset($payload['exp']) && (int) $payload['exp'] < time() - 60) {
            return false;
        }

        $digest = base64_encode(hash('sha256', $body, true));

        return isset($payload['sha256']) && hash_equals($payload['sha256'], $digest);
    }

    /**
     * One call against the room service.
     *
     * Failures are logged and swallowed rather than thrown. The database is the
     * record of what the coach decided; if the media server missed one call the
     * class carries on and the next join re-applies the permission from the
     * row. Throwing here would turn a dropped packet into a 500 in the middle
     * of a lesson.
     */
    private function admin(string $method, array $payload): ?array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('LiveKit is not configured.');
        }

        try {
            $response = $this->client()->post("/twirp/livekit.RoomService/{$method}", $payload);

            if ($response->failed()) {
                Log::warning('livekit call failed', [
                    'method' => $method,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 500),
                ]);

                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('livekit unreachable', ['method' => $method, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function client(array $extraGrants = []): PendingRequest
    {
        // A room-admin token with no room named: the admin API authorises per
        // call, and scoping this to one room would need a token per request.
        $token = $this->sign([
            'iss' => $this->apiKey,
            'sub' => 'server',
            'nbf' => time() - 10,
            'exp' => time() + 60,
            'video' => ['roomCreate' => true, 'roomAdmin' => true, 'roomList' => true] + $extraGrants,
        ]);

        return Http::baseUrl(rtrim($this->httpUrl, '/'))
            ->withToken($token)
            ->acceptJson()
            ->timeout(5)
            ->connectTimeout(3);
    }

    /** HS256, written out rather than pulled in: it is three lines and one dependency fewer. */
    private function sign(array $claims): string
    {
        $encode = fn (array $part) => rtrim(strtr(base64_encode(
            json_encode($part, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        ), '+/', '-_'), '=');

        $signing = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);
        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $signing, $this->apiSecret, true)
        ), '+/', '-_'), '=');

        return "{$signing}.{$signature}";
    }
}
