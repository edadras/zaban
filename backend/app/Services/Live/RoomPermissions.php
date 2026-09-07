<?php

namespace App\Services\Live;

/**
 * What one person may do in one room.
 *
 * A learner joins able to hear and see and nothing else; the coach hands out
 * the microphone. That default is deliberate - a class of twenty that all
 * arrive unmuted is unusable in the first ten seconds.
 */
final class RoomPermissions
{
    public function __construct(
        public readonly bool $canPublishAudio = false,
        public readonly bool $canPublishVideo = false,
        public readonly bool $canSubscribe = true,
        public readonly bool $canPublishData = true,
        /** Room admin: end the class, mute others, remove people. The coach. */
        public readonly bool $isModerator = false,
        public readonly bool $canShareScreen = false,
    ) {}

    public static function coach(): self
    {
        return new self(
            canPublishAudio: true,
            canPublishVideo: true,
            isModerator: true,
            canShareScreen: true,
        );
    }

    public static function listener(): self
    {
        return new self();
    }

    public function with(?bool $audio = null, ?bool $video = null): self
    {
        return new self(
            canPublishAudio: $audio ?? $this->canPublishAudio,
            canPublishVideo: $video ?? $this->canPublishVideo,
            canSubscribe: $this->canSubscribe,
            canPublishData: $this->canPublishData,
            isModerator: $this->isModerator,
            canShareScreen: $this->canShareScreen,
        );
    }

    public function canPublish(): bool
    {
        return $this->canPublishAudio || $this->canPublishVideo || $this->canShareScreen;
    }
}
