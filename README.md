# Zaban

An adaptive English learning platform built on four printed vocabulary courses —
1,028 pages and 1,162 audio files turned into a knowledge graph, an item bank and
a set of engines that decide what a particular learner should do next.

This README is accurate about where the project actually is. Some parts are
finished and running; several are half-built; a few are designed and not started.
Each section says which.

---

## What the platform does

**Ingests real teaching material without losing any of it.** Four *English
Vocabulary in Use* books (Elementary through Advanced) become 362 units, 1,099
sections and 1,162 mapped audio files, with every derived row carrying provenance
back to the page it came from. Two commands exist purely to prove it worked:
`content:audit` re-reads the PDFs from disk and compares them with the database,
and `content:readiness` checks whether the stored content can actually drive the
engines — a database can be full and the product still broken.

**Models what a learner knows, concept by concept.** Vocabulary senses, grammar
points, pronunciation items and phrases all register in one `concepts` table, so
mastery, prerequisites and item selection work in a single id space. Mastery is
evidence of *durable retrieval*, not of a correct answer: one success cannot push
a concept past "introduced", and successes only count toward the higher bands
when they are spaced at least 20 hours apart. Cramming cannot manufacture
mastery.

**Schedules review against forgetting.** An SM-2 derived scheduler with a
per-learner ease factor, ordering due items by an explicit forgetting-curve
probability rather than by due date alone.

**Targets difficulty.** Item difficulty and learner ability share one logit
scale, so the engine can select the item whose predicted success lands in the
70–85% band — with a deliberate stretch item 12% of the time.

**Re-teaches instead of repeating.** Failing the same concept escalates through a
ladder: a different item, then an easier framing, then a change of modality, then
explicit instruction. Showing the same question again is the one thing that
reliably does not work.

**Places learners adaptively.** A real computer-adaptive test: maximum-information
item selection, per-skill estimates that stop independently once precise enough,
and an information-weighted overall ability. A confident reader finishes reading
in four items.

**Routes every AI call through one orchestrator.** Ordered provider chains with
fallbacks, a reuse cache so standard lesson media is generated once for everyone,
per-plan spend and volume limits enforced before the call, and a full cost ledger
after it. When something cannot be done properly it says so — no forced aligner
means "phoneme scoring unavailable", never a guess dressed up as a measurement.

---

## Status

| Area | State |
|---|---|
| Database schema (126 tables) | **Done** — migrated, foreign keys deferred to a final migration |
| Ingestion pipeline + audit + readiness | **Done** — extraction, import, derivation, verification |
| Learning engines (mastery, SRS, difficulty, remediation, composition) | **Done** |
| CAT placement engine | **Done** |
| AI orchestration (chains, cache, limits, ledger) | **Done** |
| API: auth, profile, placement, courses, session, exercises, reviews, progress, admin | **Live** |
| Speech scoring | **In progress** — jobs and analysis services exist; no controller or routes yet |
| Billing / subscriptions | **In progress** — services and schema exist; no routes, no payment gateway |
| Exam preparation | **In progress** — scoring services and schema exist; no routes |
| Conversation practice | **Planned** — schema only |
| Media delivery (signed URLs) | **Planned** |
| Websockets (Reverb) | **Planned** — configuration is ready, the package is not installed |
| Flutter client | **In progress** |

The current gaps are listed exhaustively at the end of
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) rather than scattered through the docs.

---

## Tech stack

**Backend** — PHP 8.4, Laravel 13, MySQL 8, Redis 7, Laravel Sanctum for API
tokens. Six named job queues (`default`, `content`, `media`, `speech`, `ai-high`,
`ai-low`) run as independent supervisor process pools.

**AI providers** — Anthropic (via the official PHP SDK) for text: tutoring,
grading, item writing, exam scoring. Higgsfield (via its CLI) for image, video
and audio generation. whisper.cpp locally for speech-to-text, so learner
recordings stay off third-party services by default. espeak-ng and a captioned
placeholder image as last-resort fallbacks.

**Client** — Flutter (Riverpod, go_router, dio), targeting web, Android and iOS.

**Ingestion tooling** — Python 3 with poppler (`pdftotext`, `pdftohtml`,
`pdfimages`). Source PDFs and audio are stored with Git LFS.

**Development environment** — Docker Compose: php-fpm, nginx, MySQL, Redis, a
queue worker, a scheduler, and MinIO standing in for S3.

---

## Quickstart

Requirements: Docker, Docker Compose and `make`. Nothing else needs to be on your
machine.

```bash
git clone <this repo> zaban && cd zaban
git lfs pull            # optional: source PDFs and audio, ~1.1 GB

make up                 # build and start; waits until everything is healthy
make migrate            # create the 126-table schema
make import             # load the curriculum committed under docs/data
make activities         # derive lesson blocks and gradable exercises
make readiness          # check the content can actually drive the engines
```

Then:

| | |
|---|---|
| API | http://localhost:8080/api/v1 |
| Health | http://localhost:8080/up |
| MinIO console | http://localhost:9001 |

`make help` lists every target. The ones you will use most:

```
make shell            # shell in the app container
make test             # run the test suite
make logs-app         # formatted Laravel log (pail)
make queue-status     # the six worker pools
make queue-restart    # after changing code that jobs run
make routes           # list registered API routes
make audit            # prove no source content was lost on import
```

**First-run notes**

- `make up` creates `backend/.env` from `.env.example` and generates an
  `APP_KEY`. Add `ANTHROPIC_API_KEY` before using any AI feature; without it the
  text chain is empty and text generation reports failure rather than pretending.
- The Higgsfield CLI and whisper.cpp are **not** installed in the image — they
  carry credentials and multi-gigabyte models. Media and speech generation
  degrade gracefully without them (that is what the provider chains are for). See
  [docs/DEPLOYMENT.md §1](docs/DEPLOYMENT.md).
- Mail is set to the `log` driver, so password-reset emails land in the log.

### Without Docker

The backend is an ordinary Laravel app. From `backend/`:

```bash
composer install
cp .env.example .env && php artisan key:generate
# point DB_* at a local MySQL 8, then:
php artisan migrate
php artisan serve
```

You will need `pdfinfo` on `PATH` for `content:audit`, and `gd`, `pcntl` and
`redis` PHP extensions for the full feature set.

---

## Layout

```
backend/            Laravel application
  app/AI/           provider abstraction: orchestrator, registry, five providers
  app/Services/     Learning, Placement, Speech, Billing, Exam, Content
  app/Console/      content:import, content:build-activities, content:audit,
                    content:readiness
  database/         20 migrations creating 126 tables
  routes/api.php    the /api/v1 surface
mobile/             Flutter client
tools/              Python extractors (PDF -> structured JSON)
sources/            the four source books and their audio (Git LFS)
docs/data/          committed extraction output, imported by content:import
docker/             Dockerfile, php.ini, fpm pool, nginx site, supervisor, MinIO init
```

---

## Documentation

| | |
|---|---|
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | how the system works and why it is shaped this way — ingestion, the concept graph, the four adaptive engines, CAT placement, AI orchestration, queue topology |
| [docs/API.md](docs/API.md) | the `/api/v1` surface: envelope, auth, error codes, every endpoint group, marked live or planned |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | production requirements, release procedure, content import, workers, storage, monitoring, backups, security checklist |
| [docs/INGESTION_REPORT.md](docs/INGESTION_REPORT.md) | what the four books actually contain and how faithfully it was extracted |
| [backend/.env.example](backend/.env.example) | every environment variable the code reads, derived from the code itself |

---

## Source material and copyright

The four source books are owned by the operator, who has confirmed rights to the
material. They are registered in the database as `copyright_status = 'owned'`, so
the pipeline may both store and deliver the source content. Provenance is
recorded on every derived row regardless, so extracted material always stays
distinguishable from generated material — see
[docs/INGESTION_REPORT.md](docs/INGESTION_REPORT.md).
