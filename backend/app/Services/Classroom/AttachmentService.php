<?php

namespace App\Services\Classroom;

use App\Models\ClassAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Pictures, video and recordings on anything a class produces.
 *
 * A photograph of a page of homework is the same object whether it hangs off a
 * question on the board, an answer to one, or a piece of submitted work, so it
 * is stored once, in the same table and on the same disk as everything else the
 * product plays. What differs is only what it is attached to.
 */
class AttachmentService
{
    /** Sensible for a phone photograph or a short clip; a lecture is not this. */
    public const MAX_KILOBYTES = 51200;   // 50 MB

    public function __construct(private readonly MaterialService $materials) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return list<ClassAttachment>
     */
    public function attachMany(Model $to, User $by, array $files, array $captions = []): array
    {
        $attachments = [];
        $position = $to->attachments()->max('position');

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $attachments[] = $this->attach(
                $to,
                $by,
                $file,
                $captions[$index] ?? null,
                (int) $position + $index + 1,
            );
        }

        return $attachments;
    }

    public function attach(
        Model $to,
        User $by,
        UploadedFile $file,
        ?string $caption = null,
        ?int $position = null,
    ): ClassAttachment {
        $kind = $this->kindOf($file);

        $asset = $this->materials->storeUpload($file, $kind, 'class_upload');

        return ClassAttachment::create([
            'attachable_type' => $to->getMorphClass(),
            'attachable_id' => $to->getKey(),
            'media_asset_id' => $asset->id,
            'uploaded_by' => $by->id,
            'kind' => $kind,
            'caption' => $caption,
            'position' => $position ?? ((int) $to->attachments()->max('position') + 1),
        ]);
    }

    /**
     * What kind of thing this is, from what the browser said it is.
     *
     * Deliberately coarse: the client needs to know whether to draw an image,
     * a player or a download, and nothing finer than that.
     */
    public function kindOf(UploadedFile $file): string
    {
        // The guessed type first: the browser's claim is the one thing in an
        // upload the uploader controls.
        $mime = (string) ($file->getMimeType() ?: $file->getClientMimeType());

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            $mime === 'application/pdf' => 'pdf',
            default => 'file',
        };
    }

    /** The shape both clients render. Media is fetched by id, never by path. */
    public function present(ClassAttachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'caption' => $attachment->caption,
            'media_asset_id' => $attachment->media_asset_id,
            'mime' => $attachment->media?->mime,
            'bytes' => $attachment->media?->bytes,
        ];
    }
}
