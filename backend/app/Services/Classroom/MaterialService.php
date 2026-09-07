<?php

namespace App\Services\Classroom;

use App\Models\ClassMaterial;
use App\Models\ClassSession;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A coach's shelf for one class.
 *
 * Extracted from the API controller because the web panel puts the same things
 * on the same shelf. A file uploaded here becomes a media asset on the disk the
 * rest of the app already plays from; a lesson or an exercise is referenced by
 * id and stays where it is. Both come out as one kind of row, which is what
 * lets a coach share a PDF and a corpus lesson with the same gesture.
 */
class MaterialService
{
    /**
     * @param  array{kind:string,title:string,body?:?string,lesson_id?:?int,exercise_id?:?int,media_asset_id?:?int,position?:?int}  $data
     */
    public function add(ClassSession $session, User $by, array $data, ?UploadedFile $file = null): ClassMaterial
    {
        $mediaId = $data['media_asset_id'] ?? null;

        if ($file !== null) {
            $mediaId = $this->storeUpload($file, $data['kind'])->id;
        }

        $pointsAtSomething = $mediaId
            || ! empty($data['lesson_id'])
            || ! empty($data['exercise_id'])
            || filled($data['body'] ?? null);

        if (! $pointsAtSomething) {
            throw new ClassroomException(
                'A material needs a file, a lesson, an exercise, or some text.'
            );
        }

        return $session->materials()->create([
            'uploaded_by' => $by->id,
            'kind' => $data['kind'],
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'media_asset_id' => $mediaId,
            'lesson_id' => $data['lesson_id'] ?? null,
            'exercise_id' => $data['exercise_id'] ?? null,
            'position' => $data['position'] ?? ($session->materials()->max('position') + 1),
        ]);
    }

    /**
     * Store a coach's upload as a media asset.
     *
     * On the same disk and in the same table as everything else the app plays,
     * so the existing signed-URL streaming endpoint serves it and there is not
     * a second way to hand a file to a learner.
     */
    public function storeUpload(UploadedFile $file, string $kind): MediaAsset
    {
        $disk = config('filesystems.default');
        $path = $file->store('class-materials/'.now()->format('Y/m'), $disk);

        return MediaAsset::create([
            'disk' => $disk,
            'path' => $path,
            'type' => match ($kind) {
                'video' => 'video',
                'audio' => 'audio',
                'image' => 'image',
                default => 'document',
            },
            'mime' => $file->getClientMimeType(),
            'bytes' => $file->getSize(),
            'origin' => 'coach_upload',
            'copyright_status' => 'owned',
            'checksum' => hash_file('sha256', Storage::disk($disk)->path($path)),
        ]);
    }

    /** The shape the clients render. */
    public function present(ClassMaterial $material): array
    {
        return [
            'id' => $material->id,
            'kind' => $material->kind,
            'title' => $material->title,
            'body' => $material->body,
            'media_asset_id' => $material->media_asset_id,
            'mime' => $material->media?->mime,
            'lesson_id' => $material->lesson_id,
            'exercise_id' => $material->exercise_id,
            'position' => $material->position,
            'is_shared' => $material->shared_at !== null,
        ];
    }
}
