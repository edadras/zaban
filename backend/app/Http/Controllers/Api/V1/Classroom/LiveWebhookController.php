<?php

namespace App\Http\Controllers\Api\V1\Classroom;

use App\Services\Classroom\RecordingService;
use App\Services\Live\LiveKitRoomProvider;
use App\Services\Live\LiveRoomProvider;
use App\Services\Live\RecordingResult;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

/**
 * The media server reporting back.
 *
 * Unauthenticated in the session sense and authenticated in the only sense that
 * matters here: LiveKit signs the body, and a request whose signature does not
 * match the API secret is dropped without being read. Without that check this
 * endpoint would be a way for anyone who learns a class's egress id to mark it
 * recorded, or to point it at a file of their choosing.
 */
class LiveWebhookController extends Controller
{
    public function __construct(
        private readonly LiveRoomProvider $rooms,
        private readonly RecordingService $recordings,
    ) {}

    public function __invoke(Request $request): Response
    {
        $body = $request->getContent();

        if (! $this->rooms instanceof LiveKitRoomProvider
            || ! $this->rooms->verifyWebhook($body, (string) $request->header('Authorization'))) {
            Log::warning('rejected an unsigned media-server webhook', ['ip' => $request->ip()]);

            return response('', 403);
        }

        $payload = json_decode($body, true);

        if (! is_array($payload)) {
            return response('', 400);
        }

        $event = $payload['event'] ?? '';
        $info = $payload['egressInfo'] ?? $payload['egress_info'] ?? null;

        if (! is_array($info) || ! str_starts_with((string) $event, 'egress')) {
            // Room and participant events are not used here. Answering 200
            // stops LiveKit retrying something we deliberately ignore.
            return response('', 200);
        }

        $this->recordings->complete($this->resultFrom($info));

        return response('', 200);
    }

    /**
     * LiveKit's egress payload, in this application's terms.
     *
     * Field names arrive in camelCase over the webhook and snake_case from the
     * API, durations in nanoseconds and sizes as strings, so all of it is read
     * defensively rather than trusted to one shape.
     */
    private function resultFrom(array $info): RecordingResult
    {
        $status = strtoupper((string) ($info['status'] ?? ''));

        $file = ($info['fileResults'] ?? $info['file_results'] ?? [])[0]
            ?? $info['file']
            ?? [];

        $durationNs = (int) ($file['duration'] ?? $info['duration'] ?? 0);

        return new RecordingResult(
            egressId: (string) ($info['egressId'] ?? $info['egress_id'] ?? ''),
            status: match ($status) {
                'EGRESS_COMPLETE' => 'complete',
                'EGRESS_FAILED', 'EGRESS_ABORTED', 'EGRESS_LIMIT_REACHED' => 'failed',
                default => 'in_progress',
            },
            filename: isset($file['filename']) ? (string) $file['filename'] : null,
            durationMs: $durationNs > 0 ? intdiv($durationNs, 1_000_000) : null,
            bytes: isset($file['size']) ? (int) $file['size'] : null,
            location: isset($file['location']) ? (string) $file['location'] : null,
            error: isset($info['error']) && $info['error'] !== '' ? (string) $info['error'] : null,
        );
    }
}
