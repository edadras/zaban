<?php

namespace App\Providers;

use App\Services\Live\LiveKitRoomProvider;
use App\Services\Live\LiveRoomProvider;
use App\Services\Live\NullRoomProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Binds whichever media server is configured.
 *
 * A singleton so the null provider's record of what it was asked to do is the
 * same object the test inspects, and so LiveKit's admin client is built once.
 */
class LiveServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LiveRoomProvider::class, function () {
            $provider = match (config('live.provider')) {
                'livekit' => new LiveKitRoomProvider(
                    apiKey: (string) config('live.livekit.key'),
                    apiSecret: (string) config('live.livekit.secret'),
                    wsUrl: (string) config('live.livekit.ws_url'),
                    httpUrl: (string) config('live.livekit.http_url'),
                    tokenTtl: (int) config('live.livekit.token_ttl'),
                ),
                default => new NullRoomProvider,
            };

            /*
             * Named but unconfigured falls back rather than throwing. A missing
             * key in .env should degrade to "the room is unavailable", which
             * the coach is shown, and not to a 500 on every classroom page.
             */
            return $provider->isConfigured() ? $provider : new NullRoomProvider;
        });
    }
}
