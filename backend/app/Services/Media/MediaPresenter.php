<?php

namespace App\Services\Media;

use App\Models\MediaAsset;
use Illuminate\Support\Facades\URL;

/**
 * Turns a media_assets row into the shape the Flutter client expects
 * (MediaRef): an absolute, short-lived signed stream URL plus type metadata.
 */
class MediaPresenter
{
    private const LINK_TTL_MINUTES = 60;

    public function present(?MediaAsset $media): ?array
    {
        if (! $media) {
            return null;
        }

        return [
            'id' => $media->id,
            'type' => $media->type,
            'mime' => $media->mime,
            'width' => $media->width,
            'height' => $media->height,
            'duration_ms' => $media->duration_ms,
            'url' => URL::temporarySignedRoute(
                'media.stream',
                now()->addMinutes(self::LINK_TTL_MINUTES),
                ['media' => $media->id],
            ),
            'expires_in' => self::LINK_TTL_MINUTES * 60,
        ];
    }

    public function presentId(?int $id): ?array
    {
        if (! $id) {
            return null;
        }

        return $this->present(MediaAsset::find($id));
    }
}
