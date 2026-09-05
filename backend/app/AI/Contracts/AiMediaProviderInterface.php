<?php

namespace App\AI\Contracts;

use App\AI\Support\MediaRequest;
use App\AI\Support\MediaResult;

/**
 * Media generation surface. Lesson code never talks to a vendor directly - it
 * asks the orchestrator for a capability and gets whichever provider is
 * configured, so swapping vendors is a config change.
 */
interface AiMediaProviderInterface extends AiProviderInterface
{
    public function generateImage(MediaRequest $request): MediaResult;

    public function generateVideo(MediaRequest $request): MediaResult;

    public function generateAudio(MediaRequest $request): MediaResult;

    /** Consistent character portrait, reusable across a course. */
    public function generateCharacter(MediaRequest $request): MediaResult;

    /** A scene illustrating a lesson situation. */
    public function generateScene(MediaRequest $request): MediaResult;
}
