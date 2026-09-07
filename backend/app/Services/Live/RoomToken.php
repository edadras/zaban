<?php

namespace App\Services\Live;

/** Everything the client needs to open the room, and nothing it does not. */
final class RoomToken
{
    public function __construct(
        public readonly string $token,
        public readonly string $url,
        public readonly string $room,
        public readonly string $identity,
        public readonly string $provider,
        public readonly int $expiresIn,
    ) {}

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'url' => $this->url,
            'room' => $this->room,
            'identity' => $this->identity,
            'provider' => $this->provider,
            'expires_in' => $this->expiresIn,
        ];
    }
}
