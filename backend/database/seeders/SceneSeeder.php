<?php

namespace Database\Seeders;

use App\Models\CefrLevel;
use App\Models\ConversationScenario;
use App\Models\Language;
use App\Models\Scene;
use App\Models\SceneBeat;
use App\Services\Scene\SceneVoiceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The acted scenes.
 *
 * One file per situation under data/scenes, because a scene is a script and a
 * script wants to be read as one - not as a row in a list of eighty. The seeder
 * is deliberately strict: a beat whose role is not in the cast, or a choice
 * question with no right answer, is a scene that would break in front of a
 * learner, and it is better for it to break here.
 *
 * Re-running this updates the scenes in place. Beats keep their ids where the
 * position still exists, so a learner's history of what they said in a scene
 * survives an edit to the line after it.
 */
class SceneSeeder extends Seeder
{
    public function __construct(private ?SceneVoiceService $voices = null)
    {
        $this->voices ??= app(SceneVoiceService::class);
    }

    private const CAMERAS = [
        'wide', 'two_person', 'speaker_closeup', 'listener_closeup', 'over_shoulder', 'first_person',
    ];

    private const ANIMATIONS = [
        'idle', 'walking', 'sitting', 'standing', 'talking', 'listening', 'pointing', 'thinking', 'greeting',
    ];

    private const GESTURES = ['none', 'open_hands', 'pointing', 'greeting', 'thinking'];

    private const EXPRESSIONS = [
        'neutral', 'happy', 'friendly', 'worried', 'sick', 'confused', 'surprised', 'angry', 'sad', 'thinking',
    ];

    private const INTERACTIONS = ['watch', 'speak', 'choose', 'recall'];

    public function run(): void
    {
        $language = Language::where('code', 'en')->first();
        if (! $language) {
            return;
        }

        $levels = CefrLevel::pluck('id', 'code');
        $scenarios = ConversationScenario::pluck('id', 'slug');

        foreach ($this->files() as $path) {
            $definition = require $path;
            $this->validate($definition, basename($path));
            $this->store($definition, $language->id, $levels, $scenarios);
        }
    }

    /** @return array<int,string> */
    private function files(): array
    {
        $files = glob(__DIR__.'/data/scenes/*.php') ?: [];
        sort($files);

        return $files;
    }

    private function store(array $d, int $languageId, $levels, $scenarios): void
    {
        DB::transaction(function () use ($d, $languageId, $levels, $scenarios) {
            $scene = Scene::updateOrCreate(
                ['slug' => $d['slug']],
                [
                    'conversation_scenario_id' => $scenarios[$d['scenario'] ?? ''] ?? null,
                    'language_id' => $languageId,
                    'cefr_level_id' => $levels[$d['cefr'] ?? ''] ?? null,
                    'title' => $d['title'],
                    'title_fa' => $d['title_fa'] ?? null,
                    'situation' => $d['situation'] ?? null,
                    'situation_fa' => $d['situation_fa'] ?? null,
                    'environment' => $d['environment'],
                    'light' => $d['light'] ?? 1.0,
                    'cast' => $d['cast'],
                    'props' => $d['props'] ?? [],
                    'vocabulary' => $d['vocabulary'] ?? [],
                    'objectives' => $d['objectives'] ?? [],
                    'estimated_seconds' => $d['estimated_seconds'] ?? 240,
                    'status' => 'published',
                    'published_at' => now(),
                ],
            );

            foreach (array_values($d['beats']) as $index => $beat) {
                SceneBeat::updateOrCreate(
                    ['scene_id' => $scene->id, 'position' => $index + 1],
                    [
                        'role' => $beat['role'],
                        'text' => $beat['text'],
                        'translation_fa' => $beat['translation_fa'] ?? null,
                        'camera' => $beat['camera'] ?? 'two_person',
                        'animation' => $beat['animation'] ?? 'talking',
                        'gesture' => $beat['gesture'] ?? 'none',
                        'expression' => $beat['expression'] ?? 'neutral',
                        'interaction' => $beat['interaction'] ?? 'watch',
                        'prompt' => $beat['prompt'] ?? null,
                        'prompt_fa' => $beat['prompt_fa'] ?? null,
                        'choices' => $beat['choices'] ?? null,
                        'accept' => $beat['accept'] ?? null,
                        'hint' => $beat['hint'] ?? null,
                        'hint_fa' => $beat['hint_fa'] ?? null,
                    ],
                );
            }

            // A scene that lost lines in an edit should not keep playing them.
            SceneBeat::where('scene_id', $scene->id)
                ->where('position', '>', count($d['beats']))
                ->delete();

            /*
             * And its voices, where they are in the repository.
             *
             * The rendered lines ship with the scenes, so a fresh installation
             * has a scene that speaks rather than a scene that has to be voiced
             * before anyone can use it. A line with no file is left silent -
             * the player shows the words and carries on.
             */
            foreach ($scene->beats()->get() as $beat) {
                $this->voices->attachFile($beat->setRelation('scene', $scene));
            }
        });
    }

    /** Refuse to seed a scene the player could not shoot. */
    private function validate(array $d, string $file): void
    {
        $fail = fn (string $why) => throw new RuntimeException("{$file}: {$why}");

        foreach (['slug', 'title', 'environment', 'cast', 'beats'] as $key) {
            if (empty($d[$key])) {
                $fail("missing {$key}");
            }
        }

        if (count($d['cast']) !== 2) {
            $fail('a scene is shot with exactly two people on stage');
        }

        $roles = array_column($d['cast'], 'role');
        if (count(array_unique($roles)) !== 2) {
            $fail('the two cast roles must differ');
        }
        if (! collect($d['cast'])->contains(fn ($c) => ($c['playable'] ?? true))) {
            $fail('one of the two roles must be playable, or the learner has nothing to do');
        }

        foreach ($d['beats'] as $i => $beat) {
            $where = "beat {$i}";

            if (! in_array($beat['role'] ?? null, $roles, true)) {
                $fail("{$where}: role is not in the cast");
            }
            if (empty($beat['text'])) {
                $fail("{$where}: no line");
            }
            foreach ([
                'camera' => self::CAMERAS,
                'animation' => self::ANIMATIONS,
                'gesture' => self::GESTURES,
                'expression' => self::EXPRESSIONS,
                'interaction' => self::INTERACTIONS,
            ] as $key => $allowed) {
                if (isset($beat[$key]) && ! in_array($beat[$key], $allowed, true)) {
                    $fail("{$where}: unknown {$key} '{$beat[$key]}'");
                }
            }

            $interaction = $beat['interaction'] ?? 'watch';

            if ($interaction === 'choose') {
                $choices = $beat['choices'] ?? [];
                if (count($choices) < 2) {
                    $fail("{$where}: a choice needs at least two options");
                }
                if (count(array_filter($choices, fn ($c) => ! empty($c['correct']))) !== 1) {
                    $fail("{$where}: exactly one option must be correct");
                }
            }

            if (in_array($interaction, ['speak', 'recall'], true) && empty($beat['prompt'])) {
                $fail("{$where}: a line the learner must produce needs a prompt");
            }

            if ($interaction === 'recall' && empty($beat['accept'])) {
                $fail("{$where}: a gap needs the word that fills it");
            }
        }
    }
}
