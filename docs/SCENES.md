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

The thirteen scenes that ship are voiced this way and their audio is in the
repository under `sources/audio/scenes/`, so a fresh installation has scenes
that speak rather than scenes that have to be voiced before anyone can use them.
`scene:voice --from-disk` re-attaches those files after a reseed.

## The look: board first, then model

Nothing is modelled before it is drawn. Each situation gets a production visual
bible - a single wide board carrying the two characters as full-body turnarounds
with three facial studies each, the room as an isometric cutaway, and the
reusable props - and the 3D is built to match it. `sources/boards/` holds the
eight, one per situation drawn from scratch; the five later scenes reuse the
rooms and cast those boards settled rather than inventing new ones.

The order matters more than it looks. A board is one image and costs a minute;
a rig is an afternoon. Settling what Aiko wears, how tall the room is and what
is on the walls while it is still a picture means the modelling has an answer to
work to instead of a series of guesses, and the scenes end up looking like one
production rather than a set of unrelated ones.

The boards are also the record. When a colour or a garment is wrong in the 3D,
the board says what it should have been.

## The modelled kit

Two files, both authored in the 3D scene builder and exported as glTF:

| File | Holds |
|---|---|
| `public/scene-kit/cast.glb` | nine rigged characters - the eight people the learner talks to, and the figure that stands in for the learner |
| `public/scene-kit/rooms.glb` | the eight rooms, furnished, each under a root named `Room_<environment>` |

Each character is one skinned mesh on a shared 21-bone skeleton, with eleven
shape keys: eight mouth positions, a smile, a frown and a blink. The skeleton is
shared deliberately - a motion authored once reads on all nine - and the weights
are rigid, one body part to one bone, which is what this level of stylisation
wants and costs nothing to evaluate.

Motion is one eighteen-second action per rig holding nine two-second motions end
to end: idle, walking, sitting, standing, talking, listening, pointing,
thinking, greeting. The player cuts them apart by name and closes each loop by
sampling the opening frame. One long action rather than nine separate ones
because the export settings are not ours to set, and a single action survives
whatever the exporter does with them.

Both files are optional. `config/scene.php` names them, the page offers a URL
only for a file that is actually on disk, and a player with neither draws the
figures and rooms it builds itself. That is not a degraded mode anyone should be
ashamed of - it is how the scenes ran before the kit existed, and a scene with
plain geometry still teaches the lesson.

### Rebuilding it

`config/scene.php` records the two scene-builder projects the kit came from, so
the files can be rebuilt rather than only copied. The build scripts are in the
projects' history; each is a single committed operation that clears the scene
and constructs everything from primitives, so re-running the latest one
reproduces the file exactly.

Two things about that export are worth knowing before touching it:

- **The figures face the other way.** The kit is authored with faces towards +Y
  and the up-axis conversion turns that into -Z, the opposite of the way the
  player's own figures face. `RiggedActor` turns the model a half-turn inside a
  wrapper, and places the wrapper rather than the rig, so the rig's exported
  rotation and its height scale both survive.
- **Bindings must be written onto the geometry, not tracked alongside it.** An
  earlier build remembered which vertices and faces each body part occupied as
  index ranges, and those ranges did not survive the write to the mesh: shoes
  ended up at chest height. Weights now go into the bmesh deform layer as each
  vertex is made and a face's material is set on the face itself.

## The player

`resources/js/scene/` — Three.js, one bundle of its own so no other page
downloads a 3D renderer. The kit is fetched before the first frame, so a scene
is never drawn twice; when it is missing or will not parse, the player builds
the figures and the room itself. The built-in figures are primitives on purpose:
a stylised person who arrives in a few kilobytes and moves correctly teaches
more than a photoreal one that never finishes downloading over a bad connection.

Shots say what they want in frame rather than how far away to stand. The stage
is whatever shape the page gives it - a tall panel on a phone held upright, a
shallow strip on a laptop - and a fixed distance that frames a face on one of
those cuts the head off on the other, so each shot names a height in metres and
the distance is computed from the camera's own field of view.

The mouth is driven by the sound itself, through a Web Audio analyser, which
works for a recorded human voice as well as a rendered one. It is amplitude, not
phonetics: loud is open, quiet is nearly closed. It reads correctly at
conversational distance and is not a viseme track, and the interface does not
claim otherwise.

Thirteen situations share eight rooms, each in its own palette with its own
furniture. That is a limit worth stating plainly: these are recognisable spaces,
not thirteen modelled interiors.

### How the page is reached

The player runs in a web view inside the app and in a browser tab, and neither
can be handed the learner's bearer token — the first would mean injecting it
into a page, the second would leave it in a URL bar. So `POST
/conversation/scenes/start` answers with `player_url`, a signed two-hour link,
and every call the page makes carries the same signature.

The trade, stated: the link is good for one run of one scene until it expires,
and anyone holding it can play that scene as that learner. It authorises nothing
else, and it is the same trade the signed media links already make.

## Proving the player works

The PHP suite covers the server: what it accepts, how it grades, what it
withholds. That is only half of it, and the half that cannot see the page.

`npm test` in `backend/` runs the player's own tests on Node's built-in runner -
no extra dependency, no browser. Two modules are worth testing this way because
they are pure: `SceneApi`, which decides what goes on the wire, and `Director`,
which decides what happens next. Both had a fault the server-side tests were
structurally unable to catch, because those tests build the request themselves
and so only ever proved the server right about a body the page never sent:

- the answer endpoint was called without `beat_id`, so every answer came back
  422 - a scene that played perfectly and could not be answered;
- a rejected answer cleared the beat being waited on, so the learner's second
  try was handed to nobody and the scene stopped dead on a turn it would never
  take again.

Beyond that, the scenes are driven in a real browser: each one loaded through
its signed link, played from the first line to the debrief, with every learner
turn answered - deliberately wrong first, to exercise the grading - and the
three-try reveal taken in full on one of them. That run is what a release should
be judged on, because it is the only thing that exercises the bundle, the kit,
the audio and the endpoints together.

## What ships

Thirteen scenes, spread across the levels and tied to the roleplay scenario the
learner already sees in conversation practice.

| Slug | Situation | Scenario | Level | Room |
|---|---|---|---|---|
| `cafe-catching-up` | Catching up with a friend | catching-up | A2 | cafe |
| `hotel-check-in` | Checking in at the hotel | hotel-check-in | A2 | hotel |
| `restaurant-ordering` | Ordering a meal | restaurant | A2 | restaurant |
| `shop-faulty-return` | Taking something back | shopping-return | A2 | shop |
| `airport-lost-luggage` | Lost luggage | airport-lost-luggage | B1 | airport |
| `clinic-sore-throat` | At the doctor: a sore throat | doctor-appointment | B1 | clinic |
| `phone-broadband-fault` | Phoning about a fault | phone-enquiry | B1 | office |
| `travel-booking` | Booking a trip | booking-travel | B1 | office |
| `classroom-extension` | Asking for an extension | university-tutor | B2 | classroom |
| `exam-speaking-discussion` | Speaking exam: the discussion | exam-interview | B2 | classroom |
| `exam-speaking-interview` | Speaking exam: the interview | exam-interview | B2 | classroom |
| `meeting-status-update` | Giving an update in a meeting | team-meeting | B2 | office |
| `office-job-interview` | A job interview | job-interview | B2 | office |

The two exam scenes are the speaking test itself, in its own order: the warm-up
questions, the long turn off a task card, and the discussion that follows. They
are graded like any other scene, so a candidate can sit the shape of the test
before they sit the test.

## Adding a scene

One file per situation under `backend/database/seeders/data/scenes/`, and
`SceneSeeder` refuses to load one the player could not shoot — a beat whose role
is not in the cast, a choice question with no right answer, a gap with no word
to fill it. Re-seeding updates scenes in place and keeps beat ids where the
position still exists, so a learner's record of what they said survives an edit
to the line after it.

Then `php artisan scene:voice --scene=<slug>` to give it voices, and
`/panel/scenes` to listen to them.

A new situation also wants a board and a room. Draw the board first, add a
`Room_<environment>` to the rooms project to match it, and give the palette a
matching entry in `resources/js/scene/room.js` so the built-in fallback still
looks like the right kind of place.
