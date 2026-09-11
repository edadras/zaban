<?php

namespace App\Http\Controllers\Speaker;

use App\Http\Controllers\Controller;
use App\Models\MediaAsset;
use App\Services\Media\MediaPresenter;
use App\Support\SpeakerLink;
use Illuminate\Http\Request;

/**
 * Serving the talking figure.
 *
 * One page, reached by a signed link, handed a character and at most one
 * recording. Everything the page needs is in the link, so there is nothing to
 * fetch afterwards and nothing to authorise beyond the signature.
 */
class SpeakerShowController extends Controller
{
    public function __construct(private MediaPresenter $media) {}

    public function __invoke(Request $request, string $character)
    {
        abort_unless($request->hasValidSignature(), 403, 'This link has expired.');

        $slug = SpeakerLink::character($character);
        $caption = (string) $request->query('caption', '');

        $audio = null;
        if ($request->filled('audio')) {
            $asset = MediaAsset::find($request->integer('audio'));
            // Only audio, and only through the presenter, so this cannot be
            // turned into a way to hand somebody a signed link to any file in
            // the library by guessing an id.
            if ($asset && str_starts_with((string) $asset->mime, 'audio/')) {
                $audio = $this->media->present($asset)['url'] ?? null;
            }
        }

        return view('speaker.show', [
            'title' => $caption !== '' ? $caption : __('Speaker'),
            'caption' => $caption,
            'bootstrap' => [
                'character' => $slug,
                'audio' => $audio,
                // The window inside the track, when the caller knew one. A
                // whole exercise recording behind a single line is a figure
                // standing in silence for half a minute.
                'from' => $request->filled('from') ? $request->integer('from') : null,
                'to' => $request->filled('to') ? $request->integer('to') : null,
                'autoplay' => $request->boolean('autoplay'),
                'expression' => 'friendly',
                /*
                 * The modelled kit when it is on disk, and nothing when it is
                 * not: the page then draws the figure it builds itself, which
                 * moves its mouth just as correctly.
                 */
                'kit' => SpeakerLink::kitAvailable()
                    ? ['cast' => asset((string) config('scene.kit.cast'))]
                    : null,
                'strings' => [
                    'play' => 'بشنوید',
                    'playing' => 'مکث',
                    'noRecording' => 'برای این جمله صدایی ثبت نشده است.',
                    'noAudio' => 'صدا در این مرورگر فعال نشد.',
                ],
            ],
        ]);
    }
}
