<?php

namespace App\Services\Live;

/**
 * Who the media server thinks is connecting.
 *
 * `identity` is the stable key the provider matches on when the coach mutes
 * somebody, so it is derived from the user id and never from a display name -
 * two learners called Sara must not become one participant.
 */
final class RoomIdentity
{
    public function __construct(
        public readonly string $identity,
        public readonly string $name,
        public readonly array $metadata = [],
    ) {}

    public static function forUser(int $userId, string $name, array $metadata = []): self
    {
        return new self("u{$userId}", $name, $metadata);
    }

    /** The user id back out of an identity, or null if it is not one of ours. */
    public static function userId(string $identity): ?int
    {
        return preg_match('/^u(\d+)$/', $identity, $m) ? (int) $m[1] : null;
    }
}
