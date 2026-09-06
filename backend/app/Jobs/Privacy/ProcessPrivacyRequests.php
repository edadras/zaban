<?php

namespace App\Jobs\Privacy;

use App\Models\PrivacyRequest;
use App\Services\Privacy\PrivacyRequestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Carries out the export and erasure requests people have made.
 *
 * One at a time and one failure at a time: an export that throws must not stop
 * the erasure queued behind it, so each request is caught on its own and marked
 * failed. A failed request stays visible rather than silently retrying forever,
 * because someone has to look at why.
 */
class ProcessPrivacyRequests implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $limit = 50)
    {
        $this->onQueue('default');
    }

    public function handle(PrivacyRequestService $service): void
    {
        $done = 0;
        $failed = 0;

        PrivacyRequest::where('status', 'pending')
            ->orderBy('id')
            ->limit($this->limit)
            ->get()
            ->each(function (PrivacyRequest $request) use ($service, &$done, &$failed) {
                try {
                    $service->process($request);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::error('privacy.request_failed', [
                        'request' => $request->id,
                        'type' => $request->type,
                        'message' => $e->getMessage(),
                    ]);
                }
            });

        $expired = $service->purgeExpiredExports();

        if ($done || $failed || $expired) {
            Log::info('privacy.processed', [
                'completed' => $done,
                'failed' => $failed,
                'exports_expired' => $expired,
            ]);
        }
    }
}
