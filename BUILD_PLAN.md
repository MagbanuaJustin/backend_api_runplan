# RunPlan AI — Build Plan & Spec Conformance

Spec: `PROJECT SPECIFICATION.md` v1.1 (Team SDLC worksheet 2026-10-15).
Audited: API `5fee220` + LavaLust 4.6.0, frontend `runplan_frontend` (React 19 / Vite 8).
**Strava is explicitly excluded from this plan** (spec §5.5 policy gate). Everything here is built so
that a Strava connector can be added later without ever entering the analytics or AI path.

Legend: ✅ done · 🟡 partial · ❌ missing · ⚠️ built but deviates from spec

---

## 1. Conformance matrix

### Functional requirements

| ID | Requirement | Status | Gap |
|---|---|---|---|
| FR-01 | Register/login/logout, **password recovery**, Google sign-in, reCAPTCHA, profile | 🟡 | email/pw auth ✅, profile ✅. **Missing:** recovery, Google, reCAPTCHA, account deletion |
| FR-02 | Personalised plan generation, Claude-assisted, backend validation, preview→approve | ⚠️ | Generator + draft/approve flow ✅ and validation is genuinely strict. **Missing:** Claude path (`ai_plan_requests` never written), preferred time-of-day not used in scheduling |
| FR-03 | Activity logging, metrics, dashboard, AI feedback | 🟡 | CRUD + pace/consistency/dashboard ✅ (well done). **Missing:** AI feedback (`ai_feedback` never written), progress charts data shape |
| FR-04 | Revise/reschedule/skip/regenerate future sessions, **validate**, version history, preserve completed | ⚠️ | reschedule/skip/rebuild + `plan_revisions` ✅. **Deviation:** rebuild applies immediately with no confirm step; `decision` never recorded |
| FR-05 | 6 integrations, isolated server-side modules, status + error reporting | ❌ | no service layer exists at all |

### Integrations (spec §5) — all six are ❌ and none are scaffolded

| # | Integration | Backend today | Tables ready | Frontend today |
|---|---|---|---|---|
| 1 | Anthropic Claude (plan draft **and** feedback) | none | `ai_plan_requests`, `ai_feedback` | none |
| 2 | OpenWeatherMap | none | `weather_cache`, `settings` | none |
| 3 | Google Calendar | none | `integration_accounts`, `calendar_event_mappings` | none |
| 4 | Firebase Cloud Messaging | none | `notification_preferences`, `push_subscriptions`, `notification_jobs` | none |
| 5 | Google Identity Services | none | `user_identities` | no button; no link flow |
| 6 | Google reCAPTCHA | none | — (never store tokens) | no widget on register |

Supporting tables already exist with sane columns and FKs — the DB work is mostly **additions**, not redesign.

### Test cases §10.2 — TC-01…TC-15 are all `Pending`, and there is **no test harness in either repo**
(no `composer.json`, no PHPUnit, no CI, no JS tests).

---

## 2. Deviations to correct (not just gaps)

| # | Finding | Why it matters | Fix |
|---|---|---|---|
| **D1** | `POST /plans/{id}/rebuild` deletes and rewrites future sessions **immediately**. Spec Module 4 workflow steps 4–6 require the UI to show affected sessions, then the runner confirms or cancels. | An unreviewed, irreversible edit to a user's plan — and it silently contradicts §2.6 "automatic acceptance of AI-generated schedules" being out of scope | Split into `rebuild` → proposal + `POST /plans/{id}/revisions/{n}/apply` / `…/reject` |
| **D2** | `plan_revisions` = (`version`, `change_summary`, `snapshot_json`). Spec §8 wants `previous_version`, `proposed_changes`, `decision`, `decision_timestamp`, `resulting_version`, `reason`. | Cannot express "proposed" or "rejected", so the audit trail the spec promises doesn't exist | Migration `012` adds the missing columns; keep the old three (already in use) |
| **D3** | `runner_profiles` has no **location** (weather needs it) and no **timezone** (`notification_preferences.timezone` is the only one). | §5.2 and §5.4 both require these | Add `location`, `location_lat/lon` or `location_query`; keep timezone on notification_preferences as the single source |
| **D4** | No password recovery anywhere — no table, no endpoint, no UI. | FR-01 requires it, and it is a reCAPTCHA-protected form per §5.7 | `password_reset_tokens` table + `POST /api/auth/forgot-password`, `/reset-password` + frontend page |
| **D5** | No account/data deletion workflow (§3.1, §9.2, §11.3 "provider data"). | Explicit privacy requirement | `deletion_requests` + `DELETE /api/account` with cascade + Google Calendar event cleanup |
| **D6** | `settings` (`provider_config_key`, `enabled`) and `integration_accounts.connection_status` exist but no **connect/disconnect/status endpoints**. | §6.1 "Integrations & Settings" screen has nothing to call | `GET /api/integrations`, `POST /api/integrations/{p}/connect`, `DELETE …` |
| **D7** | 3 backend endpoints have **no frontend consumer**: `/api/auth/me`, `/api/plans/{id}/rebuild`, `/api/plans/{id}/revisions`. | Dead surface — the revision history you just built is invisible to users | Wire into frontend: session bootstrap via `/me`, RevisionPanel |
| **D8** | Preferred **time of day** is stored (`preferred_time`) but never used when generating sessions; sessions carry only a date. | §2.3 and FR-02 both list it; and Calendar events + reminders need a start time (§5.3 "Event creation requires start and end values") | Add `planned_start_time` to `training_sessions`, set from profile at generation |
| **D9** | `activities.source` is `VARCHAR(20)` default `manual` — good — but **no query filters on it**. | TC-10/TC-15 require Strava data be excluded from analytics/AI. Excluding nothing is not enforcement; the filter must exist before a Strava path ever lands | Scope metrics + AI summaries to `source='manual'` now |
| **D10** | Framework edits in `scheme/libraries/Api.php` include the `token_hash` column name. | App schema hardcoded into a vendored framework; a LavaLust upgrade deletes it | Move to `app/config/api.php` as `refresh_token_column` |
| **D11** | `app/config/middleware.php` is empty — every controller hand-calls `require_jwt()`. | 4 new controllers coming; one forgotten call = a data leak | Add an `auth` middleware + route groups, keep per-controller checks as defence in depth |

Fixed already in this pass: **`PlanModel::build()` guard mismatch** (wrong message + the deleted
`MAX_WEEKS` upper bound that let a 52-week race produce a 1-year plan), and the 8 mistyped-shell
junk files are now untracked.

---

## 3. Build order

Each phase is independently shippable and leaves the app working. `A`=API repo, `F`=frontend repo.

### Phase 0 — Foundations & safety net *(start here)*
- A: `app/libraries/HttpClient.php` — thin cURL wrapper: timeouts, JSON in/out, typed errors, **no** new composer deps.
- A: `app/config/integrations.php` — all provider config from `env()`, each with a `configured` flag; `GET /api/integrations/status` reports which are live. Core must keep working when every flag is off (spec §7.3, NFR-04).
- A: `.env.example` for real (currently only `MIGRATION_ENABLED`) + README run instructions.
- A: `composer.json` + PHPUnit + a `php -l` lint script, and GitHub Actions (lint, static analysis, tests). **Nothing else is safe to build at volume until a machine can check it** — this sandbox has no PHP.
- A: layering fix — add `UserModel`, `SessionModel`, `PasswordResetModel`; remove all `$this->db->…` from `Auth.php`, `Plans.php`. Raw SQL stays raw (it is correctly parameterised); the sin is layering, not injection.
- A: `auth` middleware (D11).

### Phase 1 — Account completion: Google sign-in, reCAPTCHA, recovery, deletion *(FR-01)*
- Migration `012` (new columns + `password_reset_tokens` + `deletion_requests`).
- `app/libraries/GoogleIdentity.php` — JWKS fetch+cache, ES256 verify of the `credential` JWT, issuer/audience/expiry/nonce checks, `user_identities` mapping, **no email-based auto-link** (§5.6, TC-12).
- `app/libraries/Captcha.php` — reCAPTCHA verify endpoint, score/action check, "provider unavailable ⇒ do not treat as verified" (§5.7).
- `Auth::google`, `forgotPassword`, `resetPassword`, `Account::delete`.
- F: Google button, `ForgotPassword`/`ResetPassword` pages, reCAPTCHA on register+recovery, `/me` bootstrap, delete-account confirm.

### Phase 2 — Anthropic Claude *(FR-02, FR-03, Module 2)*
- `app/libraries/Anthropic.php` — Messages API, `tool`/JSON-schema constrained output, bounded tokens, timeout, **never** sends anything but eligible first-party rows, records a row in `ai_plan_requests` for every attempt.
- `PlanService::propose()` — Claude draft → **existing deterministic validator** (`build()` rules) → template fallback on any failure. AI output can never bypass the rules or auto-activate (TC-03).
- `CoachService::feedback()` — compute metrics in PHP, send only the summary, validate the reply shape, store in `ai_feedback`; on failure return status not fabricated text (TC-14).
- F: AI feedback panel with period + disclaimer, plan-provenance badge ("AI-drafted / template fallback").

### Phase 3 — Revision flow per spec *(FR-04, D1/D2)*
- Proposal-then-apply as in D1, `decision`/`reason` recorded, revision list surfaced.
- F: `RevisionPanel` — diff of affected sessions, accept/cancel, history.

### Phase 4 — OpenWeatherMap + reminders *(Module 1/5, D3/D8)*
- `Weather.php` — forecast product per §5.2 (horizon/quota checked first), cache in `weather_cache` with `expires_at`, "stale/unavailable" never invented.
- `planned_start_time` from `preferred_time`; timezone resolution for display + reminders.
- Reminder scheduler: `notification_jobs` rows created/updated on session create/reschedule (D8 makes a start time possible).
- F: weather strip on Calendar/Log-Activity; `ComingSoon` → real Settings/Integrations screen.

### Phase 5 — Google Calendar *(§5.3)*
- OAuth code flow, tokens encrypted in `integration_accounts`, scopes minimal.
- Idempotent create/update via `calendar_event_mappings.provider_event_id` — reschedule **updates** the mapped event (TC-07); sync failure marks pending, never rolls back the internal plan.

### Phase 6 — Firebase Cloud Messaging *(§5.4)*
- Service worker + VAPID/web-push key, `push_subscriptions` register/unregister, `revoked_at` hygiene, quiet hours, `notification_jobs` worker run by cron (must not depend on an open tab).
- F: permission prompt *after* explaining purpose; in-app fallback when denied.

### Phase 7 — Verification & docs *(§10)*
- PHPUnit covering TC-01…TC-15 minus Strava, integration tests per provider with a fake HTTP transport (the reason Phase 0's client wrapper exists).
- README: env matrix, migration/deploy steps, how to disable any integration (spec §11.3).

---

## 4. Decisions I need before Phase 1

1. **reCAPTCHA product/version** — spec §5.7 and §12 leave this open. Enterprise reCAPTCHA (keyless, score-based) vs reCAPTCHA v2 checkbox. Changes the widget, the verify payload, and the failure UX.
2. **Google Calendar OAuth** — the API is a headless JSON API with no browser session; do we do a real code flow with a redirect URI on the SPA origin, or paste-code for now?
3. **FCM vs plain Web Push** — spec says FCM, but FCM's *legacy* web push path is being retired by Google; plain VAPID Web Push + a PHP sender needs no Firebase project. Which does your adviser's rubric expect?
4. **Anthropic model + budget cap** — which model, max tokens, and monthly call ceiling for a school project on a personal key (spec §5.1 asks for it explicitly).
5. **Is the React contract frozen?** I would add fields (`planned_start_time`, `location`, revision `decision`) — additive is safe, but tell me if another teammate owns those pages.
6. **Do I edit `runplan_frontend` directly**, and may I push branches to it?

## 5. Environment notes (from your HeidiSQL screenshot)
Laragon · MySQL 8.4.3 · `127.0.0.1` · db `runplan_db` · user `root`, no password · `utf8mb4` · API proxied at `127.0.0.1:3000` by Vite. 19 tables present, so migrations have run. `runplan_ai` is a second database on the same server — confirm which one is the live target before I write `012`, so I don't migrate the wrong one.
