<?php

namespace App\Http\Controllers\Panel;

use App\Models\Scene;
use App\Models\SceneBeat;
use App\Services\Media\MediaPresenter;
use Illuminate\Http\Request;

/**
 * Listening to the acted scenes before learners do.
 *
 * Not an editor: the scenes are written in the repository, where they can be
 * reviewed in a diff like the rest of the course. What cannot be reviewed in a
 * diff is whether a line actually sounds like the line, and that is the whole
 * job of this screen - play each one, and say whether the audio is right.
 *
 * It matters most for audio cut out of a course recording by the silence
 * segmenter, which is arithmetic and cannot read: it can hand line four the
 * utterance that belongs to line five, and only a person listening will know.
 */
class SceneReviewController extends PanelController
{
    public function __construct(private MediaPresenter $media) {}

    public function index()
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');

        $scenes = Scene::query()
            ->with('cefrLevel')
            ->withCount([
                'beats',
                'beats as voiced_count' => fn ($q) => $q->whereNotNull('audio_media_asset_id'),
                'beats as approved_count' => fn ($q) => $q->where('audio_review_status', 'approved'),
                'beats as cut_count' => fn ($q) => $q->where('audio_method', 'silence_segmentation'),
            ])
            ->orderBy('slug')
            ->get();

        return view('panel.scenes.index', ['scenes' => $scenes]);
    }

    public function show(Scene $scene)
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');

        $scene->load('beats.audio', 'cefrLevel');

        return view('panel.scenes.show', [
            'scene' => $scene,
            'lines' => $scene->beats->map(fn (SceneBeat $beat) => [
                'beat' => $beat,
                'audio' => $this->media->present($beat->audio),
            ]),
        ]);
    }

    /**
     * Record a person's judgement on one line's audio.
     *
     * Rejecting does not delete the file. A cut that is one line out is usually
     * fixed by re-running the binding with different settings, and throwing the
     * recording away first would make that harder rather than safer.
     */
    public function review(Request $request, Scene $scene, SceneBeat $beat)
    {
        $this->allow($this->me()->isAdmin(), 'این بخش برای مدیران سامانه است.');
        abort_unless($beat->scene_id === $scene->id, 404);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected,pending'],
        ]);

        $beat->update(['audio_review_status' => $data['status']]);

        return back()->with('status', 'وضعیت صدای این جمله ثبت شد.');
    }
}
