# Acted scenes

Conversation practice with the room drawn around it.

The roleplay scenarios put a character on the other side of a text box. A scene
puts that character in a clinic, or at a check-in desk, or across a restaurant
table, writes the exchange out line by line, and lets the learner take one of
the parts. It is drawn in real time by WebGL rather than played as a video,
which is the point: a learner can stop on a line, hear it again more slowly, say
it themselves and be answered, and the same scene behaves differently every time
they play it. A rendered film could do none of that.

## What a scene is made of

| Table | Holds |
|---|---|
| `scenes` | the situation, the room, the two people on stage, the props and the vocabulary being taught |
| `scene_beats` | the lines, in order: who says it, the camera cue, the face and hands, the audio, and what the learner is asked to do |
| `scene_sessions` | one learner's run of one scene: which part they took, how far they are, what they scored |
| `scene_attempts` | every go at every line they were asked to produce, and how close it was |

A beat asks one of four things:

- **watch** — they watch it happen.
- **speak** — they say the line. The microphone is used where the browser has a
  recogniser; otherwise they type it.
- **choose** — they pick what the person should say next, from options written
  to fail in a specific, teachable way.
- **recall** — they supply a word missing from the line.

A run has three settings. `watch` asks nothing. `guided` keeps the beats the
scene itself marks. `roleplay` promotes every line of the chosen role to a
spoken one, which is the same scene at its hardest.

## Where the decisions are made

On the server, without exception. The client draws the room and moves the
mouths; whether an answer counted is decided by `SceneDirector`, and the line a
learner still owes is never sent to the page until they have earned it or run
out of tries. A scene that graded itself in the browser would be a scene anyone
could clear by editing a variable, and the mastery the rest of the course
records against it would be worth nothing.

Marking is lexical rather than a model call — `LineMatcher`, a word-level edit
distance that expands contractions, ignores punctuation and forgives a
one-letter mishearing in a long word. That is a deliberate limit. It is fair
about whether the sentence was produced, and it says nothing at all about how it
was pronounced; pronunciation scoring is the speech pipeline's job and stays
there.

Three tries. A wrong answer costs one and comes back with the hint and the words
that were missing; the third hands the line over and the scene moves on. A
conversation that will not continue until you say the sentence perfectly is not
a conversation.

## The voices

Two sources, in this order.

**Cut from a course recording.** `scene:voice --scene=… --recording=…` runs the
unit's audio through ffmpeg's silence detector, splits it into utterances and
gives one to each line. The line then speaks in the voice that actually recorded
that unit. Two safeguards matter:

- It binds nothing unless the utterances and the lines come out even. A scene
  where every voice is one line late is worse than a scene with no audio, and
  the learner would have no way to tell that the software was wrong rather than
  them. Where there are more utterances than lines — a speaker pausing
  mid-sentence — the closest pairs are joined first, and only then is the count
  checked.
- Every cut is written as `pending`. It was made by arithmetic that cannot read,
  so a person listens before it counts as checked. That is what `/panel/scenes`
  is for, and it is the only thing that screen does: the scripts themselves live
  in the repository and are reviewed in a diff with the rest of the course.

The command also reports how well the utterance lengths track the line lengths.
A recording of the right conversation rises and falls with the script; an
unrelated one does not, and a low number means the wrong recording was named.

**Rendered through the project's voice chain.** `scene:voice` with no recording
speaks the remaining lines through `AiOrchestrator::audio()` — the same chain
the rest of the course uses, so scenes introduce no vendor of their own. Each
cast member has a fixed `characters.voice_id`, so a character sounds the same
across every scene they appear in, the way they already look the same across
every illustration.

The eight scenes that ship are voiced this way and their audio is in the
repository under `sources/audio/scenes/`, so a fresh installation has scenes
that speak rather than scenes that have to be voiced before anyone can use them.
`scene:voice --from-disk` re-attaches those files after a reseed.

## The player

`resources/js/scene/` — Three.js, one bundle of its own so no other page
downloads a 3D renderer. The figures are built from primitives rather than
shipped as scanned humans: a stylised person who arrives in a few kilobytes and
moves correctly teaches more than a photoreal one that never finishes
downloading over a bad connection. Where a rig exists for a cast member
(`characters.model_3d_url`, status `ready`) the loader uses it instead.

The mouth is driven by the sound itself, through a Web Audio analyser, which
works for a recorded human voice as well as a rendered one. It is amplitude, not
phonetics: loud is open, quiet is nearly closed. It reads correctly at
conversational distance and is not a viseme track, and the interface does not
claim otherwise.

Eight situations share one room in different palettes with different furniture.
That is a limit worth stating plainly: these are recognisable spaces, not eight
modelled interiors.

### How the page is reached

The player runs in a web view inside the app and in a browser tab, and neither
can be handed the learner's bearer token — the first would mean injecting it
into a page, the second would leave it in a URL bar. So `POST
/conversation/scenes/start` answers with `player_url`, a signed two-hour link,
and every call the page makes carries the same signature.

The trade, stated: the link is good for one run of one scene until it expires,
and anyone holding it can play that scene as that learner. It authorises nothing
else, and it is the same trade the signed media links already make.

## Adding a scene

One file per situation under `backend/database/seeders/data/scenes/`, and
`SceneSeeder` refuses to load one the player could not shoot — a beat whose role
is not in the cast, a choice question with no right answer, a gap with no word
to fill it. Re-seeding updates scenes in place and keeps beat ids where the
position still exists, so a learner's record of what they said survives an edit
to the line after it.

Then `php artisan scene:voice --scene=<slug>` to give it voices, and
`/panel/scenes` to listen to them.
