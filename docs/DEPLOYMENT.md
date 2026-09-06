# Deployment

How to run the Zaban backend in production, what to watch once it is running, and
what has to be true before it carries real learners.

This document describes the system as it exists. Where a production requirement
is not yet satisfied by the code, it is called out as a **gap** rather than
written as if it were done.

---

## 1. Requirements

### Runtime

| Component | Version | Notes |
|---|---|---|
| PHP | 8.4 (8.3 is the composer floor) | |
| MySQL | 8.0+ | 126 tables, heavy FK use, JSON columns |
| Redis | 7+ | cache, sessions, queues |
| nginx | 1.24+ | or any FastCGI-capable proxy |
| S3-compatible object storage | — | book audio, page images, generated media |

### PHP extensions

`pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `curl`,
`fileinfo`, plus:

| Extension | Why it is not optional |
|---|---|
| `gd` | `PlaceholderImageProvider::isAvailable()` returns false without it, removing the last fallback in the image chain |
| `pcntl` | `queue:work` needs it for job timeouts and graceful SIGTERM on redeploy |
| `redis` (phpredis) | the default `REDIS_CLIENT`; use `predis` only if you cannot install the extension |
| `intl`, `zip`, `bcmath` | framework and composer baseline |
| `opcache` | not strictly required, and a bad idea to run without |

### External binaries

| Binary | Needed by | If missing |
|---|---|---|
| `pdfinfo` (poppler-utils) | `content:audit` re-reads PDF page counts | the audit reports 0 expected pages and fails |
| `pdftotext`, `pdftohtml`, `pdfimages` | `tools/*.py` extraction | you cannot re-extract; the committed `docs/data` output still imports |
| `python3` | `tools/*.py` | same |
| `espeak-ng` | offline TTS fallback | the audio chain loses its fallback |
| `higgsfield` | image/video/audio generation | `isAvailable()` false → chain falls through → placeholder or nothing |
| `whisper-cli` + a ggml model | speech-to-text | speech features are unavailable |
| forced aligner (e.g. MFA) | phoneme-level pronunciation scoring | alignment returns an explicit "not configured" failure, never an approximation |
| `git-lfs` | fetching `sources/audio` and `sources/images` | ingestion has no source media |

The Docker image (`docker/php/Dockerfile`) installs everything except
`higgsfield` and `whisper-cli`. Those two carry credentials and multi-gigabyte
model files respectively, so they are provisioned onto the host or into a derived
image, not baked into the shared base:

- **Higgsfield**: install the CLI and run `higgsfield auth login` as the runtime
  user, or mount an authenticated credentials file and point
  `HIGGSFIELD_CREDENTIALS_PATH` at it. The CLI reads its own token — the
  application never sees it. Note that `HiggsfieldProvider` passes `HOME` through
  to the subprocess (falling back to `/root`), so under php-fpm you may need to
  set `HOME` explicitly for the CLI to find its credentials.
- **whisper.cpp**: mount the model file read-only and set `WHISPER_MODEL_PATH`.
  `isAvailable()` checks that the file exists, so a wrong path silently disables
  speech rather than erroring.

### Sizing

Start here and adjust from the metrics in §7:

| Role | Baseline |
|---|---|
| Web (php-fpm + nginx) | 2 vCPU / 4 GB, `pm.max_children` ≈ 20 per instance |
| Worker | 2 vCPU / 4 GB per worker host; more if you enable local whisper transcription, which is CPU-bound |
| MySQL | 4 vCPU / 8 GB, SSD, `innodb_buffer_pool_size` ≈ 60–70% of RAM |
| Redis | 1 vCPU / 1 GB, **persistence on** |

Redis persistence is not optional here because Redis carries the job queues. An
evicted job is a lost job. Run with `appendonly yes` and **no** `maxmemory`
eviction policy on the queue database.

---

## 2. Configuration

Every environment variable the code reads is documented in
[`backend/.env.example`](../backend/.env.example) — that file is the reference,
generated from the code rather than from a template.

Production settings that matter most:

```dotenv
APP_ENV=production
APP_DEBUG=false            # true leaks stack traces, env values and SQL through the JSON errors
APP_KEY=<base64 from php artisan key:generate>
APP_URL=https://api.example.com   # signed URLs and reset links are built from this

LOG_CHANNEL=stack
LOG_LEVEL=info             # the AI layer logs provider failures at warning

DB_CONNECTION=mysql
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

SESSION_SECURE_COOKIE=true
SANCTUM_TOKEN_PREFIX=zaban_   # lets secret scanners recognise a leaked token

FILESYSTEM_DISK=s3
AI_STORAGE_DISK=s3
```

### Secrets

`APP_KEY`, `ANTHROPIC_API_KEY`, database and object-store credentials, and mail
credentials are secrets. They belong in the platform's secret manager and are
injected as environment variables at start. Notes:

- Laravel loads `.env` **immutably** — a variable already in the process
  environment is never overwritten by the file. That is what makes injected
  secrets reliable and why the Docker stack can override `DB_HOST` without
  touching anyone's `.env`.
- Rotating `APP_KEY` requires `APP_PREVIOUS_KEYS` during the transition, or
  everything already encrypted becomes unreadable.
- There is **no** `HIGGSFIELD_API_KEY`: that provider authenticates through the
  CLI's own token file, which is a deliberate reduction in what the application
  can leak.

---

## 3. Build and release

```bash
# 1. Build
composer install --no-dev --optimize-autoloader --no-interaction

# 2. Cache the framework's derived config (must run AFTER env is in place)
php artisan config:cache
php artisan route:cache
php artisan view:cache
#    or simply:  php artisan optimize

# 3. Verify what the runtime actually sees
php artisan about
```

`config:cache` freezes `env()` reads. After it runs, `env()` returns null outside
config files — which is fine here, because every `env()` call in this codebase is
inside `config/`. Do not add one elsewhere.

### Zero-ish-downtime sequence

```
1. build the new release (composer install, optimize)
2. php artisan down --render=… (only if a migration is not backward compatible)
3. php artisan migrate --force
4. switch the symlink / roll the image
5. php artisan optimize
6. php artisan queue:restart      <- workers finish the current job, then exit
7. php artisan up
```

`queue:restart` is the step people forget. Workers are long-lived PHP processes
holding the *old* code in memory; without it, new jobs run against the previous
release until the workers happen to recycle.

Under the Docker images, step 6 is the `worker` container restarting, and the
supervisor `stopwaitsecs` values are deliberately set above each queue's job
timeout so an in-flight job finishes rather than being killed mid-write.

---

## 4. Database migration

```bash
php artisan migrate --force
php artisan migrate:status      # confirm
```

Notes specific to this schema:

- Foreign keys are added in a **final** migration
  (`2025_01_01_001500_add_deferred_foreign_keys.php`) because several tables
  reference each other cyclically. Do not reorder the migrations.
- 126 tables with wide JSON columns: `max_allowed_packet` must be generous
  (128 MB in the development config) or large `source_pages` writes fail.
- Never run `migrate:fresh` against a production database. It drops everything.

---

## 5. Content import

The content pipeline is a deploy-time procedure, not a runtime one. It is
idempotent, so it is safe to re-run.

```bash
# 0. Source media (only if you need to re-extract or run the audit)
git lfs pull

# 1. Extraction — optional. Output is committed under docs/data/,
#    so a normal deploy skips straight to step 2.
python3 tools/extract_content.py
python3 tools/extract_images.py

# 2. Load into the database. Updates rather than duplicates on re-run.
php artisan content:import
#    --book=<key>   import one book only
#    --fresh        delete previously imported curriculum first (destructive)

# 3. Derive interactive blocks and gradable items from what was imported
php artisan content:build-activities

# 3b. Build the exam papers from the authored production prompts. Writing and
#     speaking only — see the command's own output for why reading and
#     listening are empty.
php artisan content:build-exams

# 4. Release it to learners. NOT optional after step 2: everything imports as a
#    draft, and the learner-facing endpoints and the session engine serve only
#    what is published — so a re-import withdraws the whole course until this
#    runs.
php artisan content:publish --everything
#    (no flag)      only lessons that teach something and carry an activity
#    --everything   also the pages that are only pages: study skills, and the
#                   sections whose headings the scanner could not read
#    --withdraw     take it all back to draft
#    --book=<id>    one source document

# 5. Prove nothing was lost — re-reads the PDFs and audio tree from disk
php artisan content:audit

# 6. Prove the engines can actually run on it
php artisan content:readiness
```

**Treat steps 5 and 6 as release gates.** `content:audit` exits non-zero when a
page or audio file is unaccounted for; `content:readiness` reports whether
exercises are concept-linked, whether items are gradable, whether lessons have
interactive blocks, and whether there are enough placement-eligible items to run
an adaptive test. A database can be full and the product still broken — that
second command is the difference.

Both commands resolve paths through `base_path('..')`, i.e. relative to the
**repository root**, not `backend/`. Deploying only `backend/` therefore breaks
the audit. Deploy the repository, or accept that the audit cannot run in that
environment and run it in staging instead.

---

## 6. Queue workers and the scheduler

### Workers

Six queues; the reasoning behind the split is in
[ARCHITECTURE.md §9](ARCHITECTURE.md), and the reference process definitions are
in [`docker/worker/supervisord.conf`](../docker/worker/supervisord.conf):

| Queue | Procs | `--timeout` | `--tries` |
|---|---|---|---|
| `default` | 2 | 120 | 3 |
| `content` | 1 | 1800 | 2 |
| `media` | 2 | 900 | 3 |
| `speech` | 2 | 420 | 3 |
| `ai-high` | 2 | 180 | 2 |
| `ai-low` | 1 | 600 | 2 |

Rules that are easy to get wrong:

- **`retry_after` must exceed the longest job timeout on that connection.** With
  the media pool at 900 s and the default `REDIS_QUEUE_RETRY_AFTER` of 90 s, a
  long render is released back to the queue while it is still running and
  executes twice — which means paying a vendor twice for the same asset. Raise
  `REDIS_QUEUE_RETRY_AFTER` above 900, or give the media queue its own connection.
- **`--max-time=3600` recycles workers hourly.** Long-lived PHP processes leak;
  recycling is cheaper than diagnosing.
- **Use separate process pools, not one worker with a comma-separated queue
  list.** A comma list prioritises *within* a worker, so a worker busy on a
  ten-minute render cannot pick up an interactive tutor reply.
- **Send SIGTERM, wait, then SIGKILL.** `queue:work` finishes its current job on
  SIGTERM. The supervisor config allows for that; systemd needs
  `TimeoutStopSec` set above the queue's job timeout.

systemd equivalent for one pool:

```ini
[Unit]
Description=zaban queue worker (%i)
After=network.target

[Service]
User=zaban
Restart=always
RestartSec=5
TimeoutStopSec=950
WorkingDirectory=/srv/zaban/backend
ExecStart=/usr/bin/php artisan queue:work --queue=media --tries=3 --timeout=900 --max-time=3600
KillSignal=SIGTERM

[Install]
WantedBy=multi-user.target
```

### Scheduler

Exactly **one** scheduler per environment. Two means every daily job fires twice.

```
* * * * * cd /srv/zaban/backend && php artisan schedule:run >> /dev/null 2>&1
```

or, in a container, `php artisan schedule:work` as PID 1
([`docker/scheduler/entrypoint.sh`](../docker/scheduler/entrypoint.sh)).

Four tasks are registered in `routes/console.php`:

| Task | When | Why it cannot be manual |
|---|---|---|
| `billing.expire-lapsed` | hourly | a missed webhook must not leave someone entitled to what they stopped paying for |
| `billing.reconcile` | 03:20 daily | subscriptions within a day of renewal are re-read from the gateway |
| `speech.purge-expired-audio` | 02:40 daily | a learner's recordings must age out even if they never ask |
| `privacy.process-requests` | hourly | an export or an erasure someone asked for is a promise |

Each runs `withoutOverlapping`, so a long run does not stack on the next tick.
**If the scheduler is not running, none of them happen** — including the two
that are legal obligations rather than conveniences.

---

## 7. Storage

Book audio (1,162 files, 757 MB), extracted page images and every AI-generated
asset are `media_assets` rows plus bytes on a Laravel filesystem disk.

```dotenv
FILESYSTEM_DISK=s3
AI_STORAGE_DISK=s3          # where the AI layer mirrors provider output
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=…
AWS_BUCKET=…
AWS_ENDPOINT=…              # non-AWS S3 (MinIO, R2, Spaces)
AWS_USE_PATH_STYLE_ENDPOINT=true   # MinIO needs this; AWS does not
```

`league/flysystem-aws-s3-v3` is installed, so `s3` works on both disks.

**The bucket must not be public.** Nothing in this repository serves media
directly: `docker/nginx/default.conf` deliberately exposes no path into
`storage/`, and `docker/minio/init.sh` creates the development bucket private and
never grants anonymous download. Delivery is meant to run through the API, with
an entitlement check and then a signed URL or an `X-Accel-Redirect` — see
[API.md §6.5](API.md), where it is marked planned. Making the bucket public would
quietly make that check optional forever.

Generated media is mirrored into our own storage at generation time because
vendor URLs expire and a lesson that renders today must still render next year.
Budget for that: the mirror is a full second copy of everything generated.

---

## 8. Observability

### What to monitor, and the threshold that means something

**API errors**
- 5xx rate per endpoint. Anything sustained above ~0.5% is a real fault.
- 401 spikes: usually a client shipping with a stale token expectation.
- 429 rate on `auth`: 5/min per IP *and* per email, so a spike is either an attack
  or a broken retry loop in the app.
- p95 latency per endpoint. `GET /session/next` composes a session — it is the
  slowest legitimate endpoint and the first to degrade under database pressure.

**AI provider failures**
Everything is in the ledger, so this is SQL, not guesswork:

```sql
-- failure rate by provider, last hour
SELECT p.code,
       SUM(r.status='failed') AS failed,
       COUNT(*)               AS total,
       ROUND(100*SUM(r.status='failed')/COUNT(*),1) AS pct
FROM ai_requests r JOIN ai_providers p ON p.id = r.ai_provider_id
WHERE r.created_at > NOW() - INTERVAL 1 HOUR
GROUP BY p.code;
```

Alert on: failure rate above ~10% for any provider; **any** failure of the last
provider in a chain (that is a user-visible outage, not a degradation); p95
`duration_ms` climbing on `higgsfield` (renders queueing at the vendor).
`/admin/ai/failures` and `/admin/ai/providers` surface the same data.

**AI spend**
```sql
SELECT usage_date, feature, SUM(estimated_cost) cost, SUM(request_count) n
FROM ai_usage WHERE usage_date > CURDATE() - INTERVAL 7 DAY
GROUP BY usage_date, feature ORDER BY cost DESC;
```
Alert on daily cost above budget, and on a **drop in cache reuse** —
`ai_generations.reuse_count` flattening means the same media is being regenerated
instead of shared, and that is a bill that scales with users.

**Queue depth and age**
Depth per queue, and — more useful — the age of the oldest job. Depth 200 on
`ai-low` is normal; depth 20 on `ai-high` means learners are waiting. Alert per
queue, never on a global total. Also watch `failed_jobs` growth: a rising count
with a single exception class is one broken code path, not bad luck.

**Media generation failures**
`ai_requests` rows for image/video/audio features that end `failed`, plus the
count of `media_assets` whose metadata is marked as a placeholder — those are
lessons rendering with a grey card. Both belong on the content-review dashboard.

**Payment webhooks**
Once a gateway is integrated: unprocessed `payment_webhooks` rows older than a
few minutes, signature verification failures (an attack or a rotated secret), and
`payment_attempts` failure rate. A webhook queue that stops draining silently
means subscriptions stop activating.

**Speech errors**
`speech_attempts` that fail to process; whisper timeouts (`WHISPER_TIMEOUT`,
default 300 s); alignment unavailability. Watch `speech` queue latency
specifically — a learner is waiting for a pronunciation score, so a 30-second
queue there is a product failure even though it is nowhere near a timeout.

**Infrastructure**
php-fpm active vs. max children (via `/fpm-status`), MySQL connections and slow
queries, Redis memory and evictions (**any eviction on the queue database is a
lost job**), disk usage on the object store.

### Logs

Log to stdout/stderr and let the platform collect. `LOG_LEVEL=info` in
production; the AI layer logs provider exceptions at `warning`
(`ai.text.exception`, `ai.media.exception`), which is what you want to see
without turning on debug.

The `slack` log channel is configured and takes `LOG_SLACK_WEBHOOK_URL` if you
want critical-level alerts routed to a channel.

### Health checks

`GET /up` is registered in `bootstrap/app.php` and exercises the full path
(nginx → FastCGI → PHP → framework boot). Use it as the load-balancer check.
php-fpm's own `/fpm-ping` and `/fpm-status` are exposed only to the container
network in the supplied nginx config.

---

## 9. Backups

**MySQL** is the only irreplaceable store — it holds every learner's mastery
state, and that cannot be regenerated from anything.

- Nightly full logical backup (`mysqldump --single-transaction --routines`) plus
  binlog shipping for point-in-time recovery. Retain 30 days.
- **Restore-test monthly.** A backup nobody has restored is a hypothesis.
- Suggested targets: RPO 15 minutes (binlogs), RTO 1 hour.

**Object storage**: enable bucket versioning, so a bad batch regeneration cannot
destroy assets a published lesson already references. Cross-region replication if
the platform offers it. Book audio and page images are also reproducible from
`sources/` via Git LFS, which is a slow but genuine second copy.

**Redis** needs AOF persistence so a restart does not drop queued jobs. It is not
a backup target in its own right — losing the cache is survivable, losing the
queue means losing in-flight work.

**Secrets**: back up the secret store separately, and remember that a lost
`APP_KEY` makes every encrypted column unreadable. It is as critical as the
database.

**What is *not* a backup**: the `sources/` tree in Git LFS covers the source
material only. Everything derived — the concept graph, the item bank, generated
media, and above all learner state — exists only in the database and the object
store.

---

## 10. Security checklist

Work through this before the platform carries real users.

**Transport and headers**
- [ ] TLS everywhere; HSTS at the edge.
- [ ] `APP_DEBUG=false`. Non-negotiable.
- [ ] `SESSION_SECURE_COOKIE=true`.
- [ ] The supplied nginx config sets `X-Content-Type-Options`, `X-Frame-Options`,
      `Referrer-Policy` and a restrictive CSP, and turns off `server_tokens`.

**Authentication**
- [ ] `SANCTUM_TOKEN_PREFIX` set, so a leaked token is detectable by scanners.
- [ ] `SANCTUM_STATEFUL_DOMAINS` tight — never a wildcard. The mobile client does
      not need it at all.
- [ ] `BCRYPT_ROUNDS=12` minimum.
- [ ] Verify that login and forgot-password remain non-enumerating (they are today:
      one message for both failure modes, and always-success on reset requests).

**Rate limiting**
- [ ] **Attach the `api` limiter to the authenticated route group.** It is defined
      in `AppServiceProvider` (120/min per user) but `bootstrap/app.php` does not
      apply a `throttle` middleware to the API group, so authenticated endpoints
      are currently unthrottled. This is the single most important open item on
      this list.
- [ ] Apply `throttle:ai` and `throttle:speech` to the endpoints that spend
      provider credit as they are built — they are defined and unused today.
- [ ] `throttle:auth` (5/min per IP and per email) is applied. Confirm it survives
      any route refactor.
- [ ] Add edge rate limiting too; application-level limiting still costs a PHP
      worker per request.

**Media**
- [ ] Object-store bucket is private; no public read.
- [ ] Media delivery goes through an entitlement check and a **short-lived signed
      URL** or `X-Accel-Redirect`. Not built yet — do not open the bucket as a
      workaround.
- [ ] `APP_URL` correct, since Laravel's signed-URL verification depends on it.
- [ ] Avatar uploads are validated by mime and size (4 MB) and stored on the
      public disk — confirm that is still the intent when media delivery lands.

**Webhooks**
- [ ] Verify the gateway signature on every payload, before any parsing.
- [ ] Idempotency: record the provider event id in `payment_webhooks` and ignore
      repeats. Gateways retry aggressively and will deliver the same event twice.
- [ ] Keep the raw payload for dispute investigation.
- [ ] Never trust amounts or subscription state from the payload alone; confirm
      against the gateway API.

**Secrets**
- [ ] No secret in Git. `backend/.env` is git-ignored; `.env.example` contains no
      real values.
- [ ] Injected from a secret manager at runtime, not baked into images.
- [ ] Rotation runbook exists, including `APP_PREVIOUS_KEYS` for `APP_KEY`.
- [ ] The Higgsfield CLI's credentials file is readable only by the runtime user.

**Authorisation**
- [ ] `EnsureAdmin` gates every `/admin` route (it does) and admits only
      `admin`, `editor`, `reviewer`.
- [ ] Ownership checks on learner resources return **404, not 403** — confirmed
      in `SessionController` and `PlacementController`; keep that pattern in new
      controllers.

**Audit and privacy**
- [ ] Write `audit_logs` rows for every admin action that changes data —
      especially `POST /admin/ai/limits`, content publish decisions and user
      record edits. The table and endpoint exist; make sure new admin write paths
      populate it.
- [ ] `privacy_requests` are fulfilled hourly by `ProcessPrivacyRequests`.
      Confirm the scheduler is actually running before launching anywhere with
      erasure obligations: the code is there, and a stopped scheduler makes it
      a promise again. An erasure empties the account row rather than deleting
      it, because invoices are joined to it and must survive.
- [ ] Speech recordings are personal data. Respect `speech_consent_given` and
      `speech_retention_days` (1–730). `PurgeExpiredSpeechAudio` is scheduled
      at 02:40 daily — again, only if the scheduler runs.
- [ ] Keep transcription local (the default `whisper` chain) unless a learner has
      consented to something else.

**Dependencies**
- [ ] `composer audit` in CI.
- [ ] `composer install --no-dev` in production images — `laravel/pail`,
      `pint` and PHPUnit have no business on a production host.

---

## 11. What is still missing, collected

Everything flagged above, in one place. The list is deliberately short now;
what was on it before — unattached rate limiters, no S3 adapter, no media
delivery, no gateways, an empty schedule, unprocessed privacy requests, no
Reverb — has been built, and this section said otherwise long after it was
false. If you find that again, the code is right and this file is wrong.

**Needs a decision or a credential, not code**

1. **No payment gateway is configured.** Stripe, iyzico and PayTR drivers exist
   and `GatewayManager` resolves them, but nothing in this repository holds
   credentials, so nothing charges a card. Supply them in `backend/.env` and
   test against the gateway's sandbox before taking money.
2. **`ANTHROPIC_API_KEY` is unset.** Nothing AI-backed works without it:
   marking, the tutor, handwriting recognition, the AI examiner. The
   orchestrator falls through its chain and fails honestly rather than
   inventing a score, so the failure is visible — but the feature is off.
3. **`whisper-cli` and a forced aligner are not provisioned.** Without the
   first there is no speech-to-text and speech practice is unavailable; without
   the second, phoneme-level scoring returns an explicit "not configured"
   failure and the attempt is scored on everything else.
4. **Publishing is a pipeline step, not a memory.** Everything imports as a
   draft and `content:import --fresh` puts it back to draft, so
   `content:publish` has to run after every import or the course disappears
   from every learner. `make content` does it in order; a hand-run import does
   not.

**Still genuinely unbuilt**

5. Only the `speech`, `writing` and `media` queues have producers. The
   `content`, `ai-high` and `ai-low` workers are installed and idle; that is
   cheap and it means the queue names are already right when work arrives.
6. Artwork covers 113 of 2,421 lessons and lesson audio 1,708 — the books'
   own recordings. Generated media is the AI layer's job and has not been run.

See [ARCHITECTURE.md](ARCHITECTURE.md) for why the system is shaped this way and
[API.md](API.md) for the endpoint-by-endpoint state.
