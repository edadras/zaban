# Zaban — Flutter client

The learner-facing app for the Zaban adaptive language platform. One codebase for
**web, Android and iOS**.

The app is a **renderer for the backend's decisions**. It does not decide what to
teach, whether an answer is right, how well a concept is known, or what a plan
unlocks. Those all come from the Laravel API, and every screen here is built to
display them.

---

## 1. Requirements

| Tool | Version |
|---|---|
| Flutter | 3.32 or newer (stable) |
| Dart | 3.8 or newer |
| Backend | the `backend/` Laravel app, migrated and seeded |

Dart 3.8 is the floor because `json_serializable` 6.11 writes null-aware
elements into the generated code, and a package on an older language version
will not compile them. The platform folders (`web/`, `android/`, `ios/`) are
committed, so a clone builds without `flutter create`.

## 2. Setup

```bash
cd mobile
flutter pub get

# Generate the Freezed / json_serializable code (required — the repo does not
# commit generated files).
dart run build_runner build --delete-conflicting-outputs

flutter run --dart-define=ZABAN_API_BASE_URL=http://localhost:8000
```

For the deployable web bundle:

```bash
flutter build web --release --dart-define=ZABAN_API_BASE_URL=https://learn.edadras.com
# → build/web  (~25 MB, most of it CanvasKit)
```

`*.freezed.dart` and `*.g.dart` are git-ignored, so **`build_runner` must be run
after every clone and after any model change**. While editing models:

```bash
dart run build_runner watch --delete-conflicting-outputs
```

`build.yaml` sets `field_rename: snake` globally, so every model maps
camelCase Dart fields onto the API's snake_case keys without per-field
annotations, and `explicit_to_json` keeps nested models serialising correctly.

### Backend base URL

The base URL is a compile-time constant supplied with `--dart-define`; there is
no checked-in environment file to get out of sync.

```bash
flutter run  --dart-define=ZABAN_API_BASE_URL=https://api.zaban.example
flutter build web --dart-define=ZABAN_API_BASE_URL=https://api.zaban.example
flutter build apk --dart-define=ZABAN_API_BASE_URL=https://api.zaban.example
```

Defaults when the define is absent (`lib/core/config/app_config.dart`):

| Platform | Default |
|---|---|
| Web / desktop / iOS simulator | `http://localhost:8000` |
| Android emulator | `http://10.0.2.2:8000` (the emulator's route to the host) |

All requests are sent to `<base>/api/v1`.

### Platform notes

- **Android**: `RECORD_AUDIO` and `INTERNET` are declared in `AndroidManifest.xml`.
- **iOS**: `NSMicrophoneUsageDescription` is set in `Info.plist`.
- **Web**: microphone capture requires a secure context (`https://` or
  `localhost`). Recordings are read back from the browser blob and uploaded as
  bytes; the native platforms upload a file.
- **CORS / Sanctum**: the API is called with a bearer token (personal access
  token), not cookies, so the backend needs `Authorization` allowed in CORS and
  `auth:sanctum` on the v1 routes.

## 2.5 Languages

The interface is English and Persian. Strings are looked up by the English
sentence the widget says (`context.t('Continue')`), so a missing entry shows
that sentence rather than a key — see `lib/core/i18n/strings.dart` for why that
trade was made against ARB identifiers.

Adding a language is two steps: a catalogue beside `fa.dart`, and its locale in
`Strings.supported`. Right-to-left needs no work of its own — the locale carries
the direction and Flutter mirrors the layout — but it does need checking, which
is what `test/core/i18n/localisation_test.dart` does.

The picker in Settings names each language in itself ("فارسی", "English"),
because someone who cannot read the current interface has to be able to find
their way out of it.

## 3. Architecture

Feature-first, with a shared core. Nothing in `core/` imports from `features/`.

```
lib/
  main.dart                    entry point; loads prefs, installs ProviderScope
  app.dart                     MaterialApp.router + themes
  core/
    config/                    compile-time configuration
    network/                   Dio client, endpoint map, envelope parser, interceptors
    error/                     ApiException + transport→exception mapping
    storage/                   secure token store, shared preferences
    router/                    GoRouter, route table, auth/placement redirects
    theme/                     design tokens + ThemeData + theme controller
    widgets/                   the design system (glass, glow, rings, radar, …)
  features/<feature>/
    data/                      models (Freezed) + repositories (API calls)
    domain/                    contracts and pure state types, where a feature
                               has one worth naming (auth today)
    presentation/              controllers (Riverpod) + screens + widgets
```

Features: `auth`, `onboarding`, `placement`, `home` (daily session), `lesson`,
`speech`, `conversation`, `review`, `progress`, `exam`, `subscription`,
`profile`.

**State**: Riverpod 2 (`Notifier`, `AsyncNotifier`, `FamilyAsyncNotifier`,
`FutureProvider`). No `riverpod_generator`, so providers are ordinary top-level
finals and are readable without generated code.

**Routing**: GoRouter with a `StatefulShellRoute.indexedStack` for the five tabs
(Today / Review / Talk / Progress / You). Full-screen flows — session runner,
lesson, speech, exam, plans — sit outside the shell. The redirect enforces two
gates, both driven by server state: signed in, and placement complete
(`profile.placement_status`).

**Networking**: one Dio instance with three interceptors.

1. `AuthInterceptor` (a `QueuedInterceptor`) attaches the Sanctum bearer token,
   and on a 401 performs a single refresh and replays the request. Parallel 401s
   share one refresh. The refresh and replay run on a second, interceptor-free
   Dio so the queue cannot deadlock. A failed refresh clears the token and emits
   `AuthEvent.sessionExpired`, which the auth controller turns into a sign-out.
2. `ErrorInterceptor` normalises every failure — transport error, 4xx, or a 2xx
   body that still contains an `error` object — into an `ApiException`.
3. `LoggingInterceptor` (debug builds only; never logs the Authorization header).

**The envelope**. Every response has the shape produced by
`App\Support\ApiResponse`:

```json
{ "data": …, "meta": { "page": 1, "per_page": 20, "total": 57, "last_page": 3 } }
{ "data": null, "error": { "code": "validation_failed", "message": "…", "details": {} } }
```

`ApiEnvelope.parse` handles all of it, including a 204 with no body and a
non-envelope payload from a misbehaving proxy. `ApiException.kind` maps the
backend's **code** first and the HTTP status second, so `subscription_required`
is a paywall regardless of whether it arrives as 402 or 403.

## 4. API contract

Paths are relative to `/api/v1` and follow `backend/routes/api.php` plus the
module route files (`billing`, `exam`, `speech`). The full map lives in
`lib/core/network/api_endpoints.dart`.

| Area | Endpoint |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Placement | `POST /placement/start`, `GET /placement/{id}/next`, `POST /placement/{id}/submit`, `GET /placement/{id}/result` |
| Session | `GET /session/next`, `POST /session/start`, `GET /session/{id}`, `POST /session/{id}/activities/{activityId}/complete`, `POST /session/{id}/complete` |
| Curriculum | `GET /courses`, `GET /units/{id}`, `GET /lessons/{id}` |
| Exercises | `GET /exercises/{id}`, `GET /exercises/{id}/hint`, `POST /exercises/{id}/submit` |
| Review | `GET /reviews/due`, `GET /reviews/counts` |
| Progress | `GET /progress/dashboard`, `GET /progress/skills`, `GET /progress/history`, `GET /progress/trend` |
| Speech | `POST /speech/attempts` (multipart), `GET /speech/attempts`, `GET /speech/attempts/{id}`, `GET /speech/profile` |
| Exam | `GET /exams/types`, `POST /exams/attempts`, `GET /exams/attempts/{id}/next-task`, `POST /exams/attempts/{id}/tasks/{taskId}/response`, `POST /exams/attempts/{id}/finish`, `GET /exams/attempts/{id}/results` |
| Billing | `GET /billing/plans`, `GET /billing/subscription`, `POST /billing/checkout`, `POST /billing/subscription/cancel` |
| Profile | `GET/PATCH /profile`, `PATCH /profile/settings` |

Contract details the client leans on:

- **`GET /session/next` returns the whole composed session.** Each activity
  carries its resolved subject inline under `subject`, tagged `kind:
  "exercise"` or `"lesson_block"`, plus `why` — the selection audit trail the
  UI turns into "Due for review". The client walks that list in order; it never
  resolves `subject_type`/`subject_id` itself and never composes a session.
- **Grading is server-side.** Options are sent without `is_correct`; the
  accepted answer appears only in the `POST /exercises/{id}/submit` response,
  which is what the feedback panel renders. Responses are sent in the shape the
  grader keys against: an option id for a choice, the assembled sentence for a
  reorder, the rewritten sentence for an error correction.
- **The home screen has no endpoint of its own.** It is a view over
  `GET /progress/dashboard` (plus `/progress/history` for the week strip), so
  home and the dashboard can never disagree about a counter.
- **No token refresh.** Sanctum personal access tokens do not expire, so a 401
  means the token was revoked: the interceptor clears the session and the
  router returns to sign-in. The refresh path stays in place behind a
  refresh-token check in case the API grows one.
- **Every exam score arrives with its disclaimer** (`estimate.disclaimer`), and
  the result screen shows it verbatim.

### Not yet on the server

`onboarding` and `conversation` are built against documented shapes
(`features/*/data/models/*.dart`) but their routes do not exist yet. Calls fail
as ordinary 404s, which the screens render as an error state rather than
crashing. The endpoints are grouped at the bottom of `api_endpoints.dart`.

Two smaller gaps worth closing on the backend:

- `UserResource.settings` omits `notifications_*`, `reminder_enabled`,
  `speech_retention_days` and `allow_speech_for_model_improvement`; the settings
  screen shows the column defaults until they are exposed.
- Lesson blocks carry `media_asset_id` and `config.audio_media_asset_id` rather
  than a resolved URL, so audio blocks render without a player. The client reads
  `audio.url`, `media.url` or `config.audio_url` — whichever appears first.

## 5. Design system — "red glassmorphism"

Dark is the product's identity; light is fully defined and selectable in
Settings. Nothing in the app hard-codes a colour: widgets read tokens through
theme extensions (`context.colors`, `context.glass`, `context.motion`).

**Tokens** (`lib/core/theme/tokens/`)

| File | Contains |
|---|---|
| `color_tokens.dart` | `ZabanColors` — canvas/surface, glass fill + border + highlight, accent scale, text roles, semantics; `.dark()` and `.light()` |
| `glass_tokens.dart` | `ZabanGlass` — blur radii, fill opacity, border width, and an `enabled` flag that flattens the whole system at once |
| `motion_tokens.dart` | `ZabanMotion` — durations (90 / 160 / 240 / 420 ms) and curves; `.reduced()` collapses them to zero |
| `dimension_tokens.dart` | `Spacing` (4pt scale), `Radii`, `Breakpoints`, `ScreenSize` |
| `shadow_tokens.dart` | ambient / lifted shadows and the accent `glow(intensity)` bloom |
| `typography_tokens.dart` | the type scale (tight display tracking, generous body line height) |

**Rules the system encodes**

- The ground is near-black charcoal (`#07070A`), never red. Red is a *light
  source*: gradients on the single primary action, a soft bloom, a hairline
  border, an accent-tinted glass wash. There is no bright red background
  anywhere, no neon, no drop-shadowed "gamer" chrome.
- Glass = translucent fill + backdrop blur + 1px translucent border + a 1px
  specular line along the top edge. That is the whole material.
- Exactly one glowing primary action per screen. The glow is wayfinding, not
  decoration.
- Motion is short and eased-out; the only long animation is the ambient
  background drift, which stops entirely when the platform asks for reduced
  motion.

**Reusable widgets** (`lib/core/widgets/`): `GlassPanel`, `GlassCard`,
`GlowButton`, `ProgressRing`, `SkillRadar`, `StreakBadge`, `AmbientBackground`,
`StatTile`, `LevelBadge`, `TagPill`, `SectionHeader`, `TrendSparkline`,
`PressScale`, `ResponsiveContent`/`ResponsiveGrid`/`ResponsiveBuilder`,
`ZabanScaffold`, `AdaptiveNavigationShell`, and the loading/error/empty views.

**Responsiveness**: no fixed screen sizes. `LayoutBuilder` + `Breakpoints`
(compact < 680 < medium < 1080 ≤ expanded) drive the layout: a floating glass
tab bar on phones becomes a rail and then an extended rail; the dashboard and
home stack on phones and go side-by-side on desktop; long-form text stops at a
readable measure.

### Adding a lesson block type

`lib/features/lesson/presentation/blocks/block_renderer.dart` holds a
`Map<String, BlockBuilder>` registry. Add one entry; nothing else in the app
switches on `block.type`. Resolution order is: registry → the block's embedded
exercise → `UnknownBlock`, which shows whatever text the block carries plus a
Continue button, so an unrecognised type can never trap a learner mid-session.

Covered today: `source_text`, `image_scene`, `flashcard`, `listen_and_choose`,
`repeat_after_speaker` (plus `pronunciation_drill` / `open_speaking`), `story`,
`ai_intro`, `grammar_note`, and every exercise template through
`ExerciseRenderer`: `multiple_choice`, `fill_blank`, `match`,
`sentence_reorder`, `error_correction`, with a free-text fallback for
`translation`, `dictation`, `writing_task` and anything the content pipeline
adds later.

## 6. Tests

```bash
flutter test
```

| File | Covers |
|---|---|
| `test/core/network/api_envelope_test.dart` | envelope parsing: data, meta/pagination, the error object, code→kind mapping, 204s, malformed payloads |
| `test/core/widgets/design_system_test.dart` | GlassPanel, GlassCard, GlowButton, ProgressRing, SkillRadar, StreakBadge, StatTile, LevelBadge, TrendSparkline, palette completeness |
| `test/features/lesson/block_renderer_test.dart` | every registered block type, the unknown-type fallback, and that blocks delegate (continue / rate / speak) instead of acting |
| `test/features/lesson/exercise_renderer_test.dart` | each exercise UI, the exact response payload it submits, server-rendered feedback, and the placement chrome |
| `test/features/home/session_mapping_test.dart` | the session payload's polymorphic `subject`, the selection-reason labels, and the dashboard → home-view mapping |

`test/helpers/pump_app.dart` mounts widgets with `ZabanGlass.flat()` and
`ZabanMotion.reduced()` — blur and repeating animations make `pumpAndSettle`
unreliable and add nothing to the assertions.

## 7. Conventions

- Analysis is `flutter_lints` plus strict casts/raw types
  (`analysis_options.yaml`); generated files are excluded.
- Repositories return models or throw `ApiException`. Controllers hold state.
  Widgets render state and call controllers. No widget calls Dio.
- Comments explain *why*, not *what*.
