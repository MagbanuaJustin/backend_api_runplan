# RunPlan AI — Backend REST API (`runplan_lavalust_api`)

REST API for **RunPlan AI: Adaptive Marathon Training Planner**, built on **LavaLust 4.6** (`PHP 8.2+`, `MySQL 8.x`). Paired with the companion React 19 + Vite SPA (`runplan_frontend`).

---

## Requirements

- **PHP 8.2+** with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `json`
- **MySQL 8.x** (Laragon local default: `127.0.0.1:3306`, database `runplandb`)

---

## Quick Start (Local / Laragon)

1. **Create `.env` from the template:**
   ```bash
   cp .env.example .env
   ```
   Verify your database and token keys in `.env`:
   ```dotenv
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=runplandb
   DB_USER=root
   DB_PASS=
   CORS_ORIGINS=http://localhost:5173
   JWT_SECRET=<64-hex-secret>
   REFRESH_TOKEN_KEY=<64-hex-secret>
   ```

2. **Run database migrations (first-time setup):**
   - Temporarily set `MIGRATION_ENABLED=true` in `.env`
   - Visit `http://127.0.0.1:3000/migrate/run` (or run `php public/index.php migrate/run`)
   - Set `MIGRATION_ENABLED=false` again when finished

3. **Start the API server on port `3000` (matching Vite's `/api` proxy):**
   ```bash
   php -S 127.0.0.1:3000 -t public
   ```
   *(Or `php lava serve --port=3000` if using the LavaLust CLI.)*

4. **Verify health check:**
   ```bash
   curl http://127.0.0.1:3000/api/health
   ```
   Expected response:
   ```json
   {"status":"ok","service":"runplan-api","time":"..."}
   ```

---

## Running Automated Checks & Tests

No external database or Composer vendor install is needed to run the test suite (uses PHP's built-in tokenizer + in-memory SQLite where needed):

- **Windows (PowerShell / Laragon Terminal):**
  ```powershell
  php tests/run.php
  ```
- **Git Bash / Linux / macOS / CI:**
  ```bash
  ./scripts/check.sh
  ```

---

## Core API Endpoints (Phase 0)

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/api/health` | Public | Liveness + database connectivity check |
| `OPTIONS` | `/api/*` | Public | CORS preflight (`204 No Content`) |
| `POST` | `/api/auth/register` | Public | Create runner account |
| `POST` | `/api/auth/login` | Public | Issue access JWT + `rp_refresh` HttpOnly cookie |
| `POST` | `/api/auth/refresh` | Cookie | Rotate refresh token and issue new access JWT |
| `POST` | `/api/auth/logout` | Public | Revoke refresh token and clear cookie |
| `GET` | `/api/auth/me` | Bearer | Current runner profile summary |
| `GET` | `/api/profile` | Bearer | Get runner training profile |
| `PUT` | `/api/profile` | Bearer | Create or update runner training profile |
| `POST` | `/api/plans/generate` | Bearer | Generate deterministic draft training plan |
| `GET` | `/api/plans/current` | Bearer | Fetch current active or draft plan with sessions |
| `POST` | `/api/plans/{id}/approve` | Bearer | Activate draft plan |
| `POST` | `/api/plans/{id}/rebuild` | Bearer | Rebuild remaining schedule from recent manual runs |
| `GET` | `/api/plans/{id}/revisions` | Bearer | List plan version history |
| `PATCH` | `/api/sessions/{id}` | Bearer | Reschedule session (`scheduled_date`) or update `status` |
| `GET` | `/api/activities` | Bearer | List manual runs (supports `?from=YYYY-MM-DD&to=YYYY-MM-DD`) |
| `GET` | `/api/activities/{id}` | Bearer | Fetch single manual run |
| `POST` | `/api/activities` | Bearer | Log manual run (and complete linked session) |
| `PUT` | `/api/activities/{id}` | Bearer | Update manual run |
| `DELETE` | `/api/activities/{id}` | Bearer | Delete manual run (and reopen linked session) |
| `GET` | `/api/dashboard` | Bearer | Consistency, weekly volume, pace & recent runs |
| `GET` | `/api/integrations/status` | Bearer | Read-only provider readiness (`core_enabled: true`) |
