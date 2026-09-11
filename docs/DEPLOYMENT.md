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

# 1b. The admin and coach website. Blade and Tailwind, served by the same
#     Laravel app at /panel — see §12. Without this step every panel page
#     500s on a missing Vite manifest.
npm ci
npm run build

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

### The web client reaches no third-party host

Worth stating because it was not true until it was tested, and the failure was
silent in both halves.

A Flutter web build, left alone, fetches two things from Google at runtime: the
CanvasKit renderer from `gstatic.com`, and the Roboto typeface from
`fonts.gstatic.com`. Neither failure produces an error a user could act on.
Without the renderer the page stays blank - there is nothing loaded that could
draw a message. With the renderer but without the font, the app draws its
cards, its fields and its buttons and renders not one character of text.

For learners in Iran that is not an edge case, it is the common case. So:

- `web/flutter_bootstrap.js` sets `canvasKitBaseUrl` to the copy already inside
  the build, and
- one font family (Vazirmatn, SIL OFL, Persian and Latin) is bundled in
  `assets/fonts/` and sits last in both fallback lists in `ZabanTypography`.

On a phone neither is reached: the platform's own renderer and font win, and
the app looks exactly as it did. Both are pinned by
`mobile/test/core/theme/web_typography_test.dart`, because an unused font is
exactly the kind of thing that gets tidied away by someone testing on a phone.

If a future build adds a third-party URL, load the deployed site once with the
network policy your learners have, not the one you have.

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

**Host access**

The application can be perfect and still be lost at this line. One password on
one root account is the whole system: the database, every learner's personal
data, the recordings of children's classes, and the keys to everything the
platform talks to.

- [ ] **Root has no password login.** Key-only, and the key has a passphrase.
- [ ] **Any password that has ever been typed into a chat window, pasted into a
      ticket, sent over email or Telegram, or shared with a contractor is
      burned.** Rotate it, do not reason about who probably saw it.
- [ ] Deploy as an unprivileged user with `sudo`, not as root.
- [ ] SSH is behind the firewall's allow-list, or a VPN, or at minimum
      `fail2ban`.
- [ ] `PermitRootLogin` is `prohibit-password` or `no`, and
      `PasswordAuthentication` is `no`.

Rotating and closing it off, in the order that does not lock you out — **keep
the current session open until the last step has been tested from a new one**:

```bash
# 1. From your own machine: put your public key on the server, while password
#    login still works.
ssh-copy-id -i ~/.ssh/id_ed25519.pub root@learn.edadras.com

# 2. Prove the key works, in a second terminal. Do not close the first.
ssh -i ~/.ssh/id_ed25519 root@learn.edadras.com 'echo key login works'

# 3. On the server: change the password anyway. A key does not retire a
#    password that is still accepted at the console or by a rescue system.
passwd root

# 4. Turn password login off.
sudo tee /etc/ssh/sshd_config.d/10-hardening.conf >/dev/null <<'EOF'
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
EOF
sudo sshd -t && sudo systemctl reload ssh

# 5. From a third terminal, confirm a password is now refused and the key is
#    not. Only then close the session you started with.
ssh -o PreferredAuthentications=password -o PubkeyAuthentication=no \
    root@learn.edadras.com   # must fail
```

Rotating the host password is also the moment to rotate what that host holds:
`APP_KEY` (with `APP_PREVIOUS_KEYS`), `LIVEKIT_API_SECRET`, the database
password, and any provider key in `app.env`.

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

## 12. The school panel

`/panel` is a server-rendered website for the two people who work at a desk: the
school's administrator and the coach. The learner's half of the product stays in
the app; this is not a second copy of it.

* **Who may open it.** Anyone who is an active owner, admin or coach at a
  school, or who carries a platform role. Session authentication, not bearer
  tokens. A learner who signs in is refused and signed straight back out.
* **How a school starts.** Only the platform administrator registers a school
  and its manager (`/panel/platform/schools`). That creates the school and,
  when needed, the manager's account. The manager then signs in, adds coaches,
  and runs classes. Schools are not self-serve from the school half of the panel.
* **What it does.** Schools and their people, coach↔learner assignment, classes
  and their weekly timetables, session preparation (uploading video, PDF, image
  and audio, writing text, picking a lesson out of the corpus), the live class
  console, attendance, and the practice lock. Platform accounts also get an
  overview, school registration, user management and the audit log.
* **Authorisation.** The panel calls the classroom services directly rather than
  its own API over HTTP, so there is one set of rules however a school comes in.
  A platform administrator is *not* automatically a school's manager: the
  platform section and the school section are different rooms.
* **The live console.** JavaScript talking to the same `/api/v1/class-sessions/*`
  endpoints the mobile client uses. The page mints one Sanctum token named
  `panel-room`, scoped to `classroom`, expiring in six hours, replaced on each
  visit and revoked on sign-out.

### The media server

Video and audio go through a `LiveRoomProvider`. `LIVE_PROVIDER=null` — the
default — records the intent and sends nothing: the timetable, the materials,
the questions, the microphone permissions and the practice lock all still work,
and only faces and voices are missing. Set `LIVE_PROVIDER=livekit` and give it
`LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`, `LIVEKIT_WS_URL` and
`LIVEKIT_HTTP_URL` to turn them on.

LiveKit is self-hosted; it needs UDP as well as the HTTPS port, so the reverse
proxy in front of Laravel is not enough on its own. Muting is pushed to the
media server *and* written as a row here, because the row is what makes a muted
learner who reloads come back muted.

### The learner's side

The app has the other half: `/classes`, the room, and the bell. It joins the
same LiveKit room through the same endpoints, and renders whatever the coach
puts on screen.

Two differences from the panel worth knowing about.

* **It polls.** The client has no websocket of its own, so the room's state is
  re-read every three seconds. The one thing where a delay would matter — being
  muted — is enforced by the media server the moment the coach decides it, so
  what arrives on the next poll is the label catching up with the microphone
  rather than the other way round.
* **Placement does not gate it.** A learner summoned into a class that starts
  now is not sent to sit the adaptive test first; the course itself is still
  gated.

Native builds need the camera: `CAMERA`, `MODIFY_AUDIO_SETTINGS`,
`ACCESS_NETWORK_STATE` and `BLUETOOTH_CONNECT` are in the Android manifest, and
`NSCameraUsageDescription` in the iOS plist. flutter_webrtc raises the iOS
floor to 13.0.

### Recording a class

Off unless `LIVE_RECORDING=true`. The coach presses record in the console, or
`LIVE_RECORDING_AUTOSTART=true` starts it with the class; ending the class stops
the recording before the room is torn down, because an egress against a deleted
room produces a truncated file.

**Three pieces have to agree, and `deploy/production/` now ships all three.**
They are listed because getting one wrong fails silently in the worst possible
way — the class is marked as recording, the coach is told it is being recorded,
and no file is ever produced:

1. **`livekit-egress`** in `compose.yml` — the worker that actually records.
   It joins the room as an invisible participant and composites it with a
   headless Chrome, which is why it is given `SYS_ADMIN`, a 1GB `/dev/shm` and
   a real core. Without this service the SFU accepts the request, queues it,
   and nobody ever takes it.
2. **`redis:` in `livekit.yaml`** — how the SFU hands the job to the worker.
   The SFU will start without it and simply never dispatch anything.
3. **`webhook:` in `livekit.yaml`** — how the finished file gets back. Without
   it the recording completes, the file is written, and the application is
   never told: the class stays "recording" for ever and the video is never
   turned into something a learner can watch.

Both `livekit.yaml` and `egress.yaml` carry `REPLACE_ME` where the API secret
goes. **Fill in both** with `LIVEKIT_API_SECRET` from `app.env` — there are two
of them now, and a worker with the wrong secret authenticates against nothing:

```bash
cd deploy/production
secret="$(grep -E '^LIVEKIT_API_SECRET=' app.env | cut -d= -f2-)"
sed -i "s|REPLACE_ME|${secret}|" livekit.yaml egress.yaml
docker compose up -d livekit livekit-egress
```

Two ways the file comes back.

* **`LIVE_RECORDING_OUTPUT=file`** (the default, and needs nothing bought). The
  worker writes to `LIVE_RECORDING_DIR` as it sees it (`/recordings`); the
  application reads `LIVE_RECORDING_LOCAL_DIR`. The `recordings` volume in
  `compose.yml` is mounted into both at those two paths, so the defaults work
  with nothing else set.
* **`LIVE_RECORDING_OUTPUT=s3`.** Point `FILESYSTEM_DISK` at the same bucket, or
  the finished file cannot be served back.

LiveKit then calls `POST /api/v1/webhooks/live`. **The endpoint verifies the
signature LiveKit puts on the body before reading a single field** — without
that it would be a way for anyone who learns an egress id to mark a class
recorded and point it at a file of their choosing. It is idempotent: LiveKit
retries a delivery it did not get a 2xx for.

**Checking it actually works**, which is worth doing once rather than finding
out from a coach: start a class, press record, and

```bash
docker compose logs -f livekit-egress     # a job is picked up
docker compose exec app ls -l storage/app/recordings   # the file appears
docker compose logs app | grep webhooks/live           # the webhook lands
```

A recording that never reaches the third line is the failure this section
exists to prevent.

The finished recording becomes an ordinary `media_asset`, so it is served by the
same signed, short-lived streaming route as everything else the app plays.

**Who may watch it back:** the coach who taught it, a manager of the school that
ran it, and anyone on the class roll — including a learner who missed it, which
is most of the point of recording one. The panel plays it at
`/panel/sessions/{id}/recording`; the app at `/classes/{id}/recording`, reached
from the class history.

### Websockets

Both clients listen on the private channel `class-session.{id}` through Reverb,
so the roster changes when the coach mutes somebody rather than up to three
seconds later. The web panel authorises through Laravel's own
`/broadcasting/auth` (session); the app through `POST /api/v1/realtime/auth`
(bearer token), which runs the same callbacks in `routes/channels.php` — the
second route exists because widening the first one would have meant loosening
the CSRF rules the panel depends on.

`GET /api/v1/realtime` tells a client where to connect. Without `REVERB_*`
configured it answers `{"driver":"null","enabled":false}` and both clients fall
back to asking the API periodically, which is a slower classroom and not a
broken one.

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
