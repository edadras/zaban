# Zaban media pack

Generates the course artwork on **your** machine, signed in as **you**, so the
unlimited model packs on your Higgsfield account are actually reachable.

## Why it runs on your machine and not in the session

The remote session that planned all this could not use your unlimited packs.
Every model it tried came back with *"Unlimited generations aren't supported"*,
and each image was billed against credits instead — your Higgsfield dashboard
still shows **Free generations in total: 0**. Signing the official CLI in
through your own browser is what tests whether those packs cover CLI and API
generations, and spends them if they do.

Nothing here automates a browser or scrapes a website. It drives `higgsfield`,
Higgsfield's own command-line tool.

## Setup — once

```bash
npm i -g @higgsfield/cli
higgsfield auth login          # opens your browser; finish the sign-in there
```

Python 3.9 or newer. No packages to install — standard library only.

## Use

```bash
python3 generate.py --check          # auth, plan, cost. Generates nothing.
python3 generate.py --limit 12       # a small batch first. Always do this.
python3 generate.py                  # everything outstanding
```

**Try the packs first.** If they work through the CLI, the whole run is free:

```bash
python3 generate.py --unlim --limit 4
```

If that comes back refused, the packs are web-app only — run without `--unlim`
and it spends credits instead. The script says so plainly rather than failing
quietly.

Other options:

```bash
python3 generate.py --kind lesson_scene --limit 50
python3 generate.py --model kling_omni_image     # override the model
python3 generate.py --retry-failed
python3 generate.py --workers 4                  # fewer parallel jobs
```

Ctrl-C is safe at any point. Progress is written after every image, so
re-running continues from where it stopped and never regenerates something
already on disk.

## What comes out

```
images/
  character_portrait/1.png
  lesson_scene/1247.png
  vocabulary_card/3310.png
  dialogue_video/13578.mp4
results.json        <- send this back
failures.json       <- only if something failed
```

Send `results.json` back and the project attaches every file to the lesson,
word or character it belongs to:

```bash
php artisan media:import results.json
```

## What is in the manifest

2,584 items, already ordered so the most useful are made first:

| kind | count | what it is |
|---|---:|---|
| `character_portrait` | 2 | the last two of the recurring cast |
| `lesson_scene` | 1,130 | one scene per lesson |
| `vocabulary_card` | 1,372 | one card per word that has a usable example |
| `dialogue_video` | 80 | the printed A/B exchanges, as short clips |

Render order is deliberate. Portraits first, because the cast anchors every
scene they appear in. Then lesson scenes, because a lesson with no artwork
looks unfinished. Then vocabulary cards, which still teach without a picture.
Within each band, lower CEFR levels come first — a beginner leans on the
picture for meaning an advanced learner takes from the text.

So if you stop half way, you stop with the more useful half made.

## Cost, if the packs turn out not to apply

Measured on the account, not guessed:

| model | credits each | 2,584 items |
|---|---:|---:|
| `soul_2` | 0.12 | 310 — **but unusable for scenes** |
| `kling_omni_image` @1K | 0.5 | ~1,290 |
| `gpt_image_2` @1K low | 0.5 | ~1,290 |
| `nano_banana` | 1 | ~2,580 |
| `gpt_image_2` @2K medium | 2.5 | ~6,460 |

`soul_2` is the cheapest and it is a trap: it is a portrait model, and asked for
a scene it produces garbled lettering across the whole frame. It was tested and
rejected, which is why it is not the default.

`kling_omni_image` produced a clean, usable hotel scene at a fifth of the price
of the default. If credits are tight, `--model kling_omni_image` is the switch
worth making.
