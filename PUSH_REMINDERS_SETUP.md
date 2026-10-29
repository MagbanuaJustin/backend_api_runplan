# Run reminder setup

RunPlan sends optional browser push reminders for planned sessions. A runner opts in on each browser/device, chooses 30 or 60 minutes before the run, and the API schedules reminders from the session start time in that runner's IANA timezone. Login email is not used as a destination. Changing Preferred time affects new or rebuilt schedules; apply a proposed plan revision to change times on an existing active plan.

## Server setup

1. Apply database migrations through migration 017. It adds an endpoint hash, longer Web Push endpoint storage, and a queue lock state. Migration 010 must already have created the notification tables. To run migrations in this LavaLust app, temporarily set `MIGRATION_ENABLED=true` in the backend `.env`, run `php public/index.php migrate/run` from the backend directory, then set it back to `false`.
2. In the backend directory, run `php console/generate_vapid_keys.php` and copy its output into the backend `.env`. Use the same VAPID key pair on every API instance. Replace `mailto:you@example.com` with an address you control.
3. Keep `VAPID_PRIVATE_KEY` and `NOTIFICATION_WORKER_TOKEN` private. The worker token also encrypts browser subscription secrets in the database; keep it stable. If you rotate it or the VAPID pair, have runners re-enable reminders on their devices. Do not put either secret in the frontend `.env` or commit `.env`.
4. Restart the PHP API. The API must have cURL and OpenSSL enabled and outbound HTTPS access to the browser push services.

## Schedule the queue worker

The dispatcher must run once per minute. For local Windows development, create a Windows Task Scheduler task that repeats every minute while Laragon's API is running. Its action can call the endpoint like this, replacing the token with the value from the backend `.env`:

```powershell
$token = 'YOUR_NOTIFICATION_WORKER_TOKEN'
Invoke-RestMethod -Method Post `
  -Uri 'http://127.0.0.1:3000/api/notifications/dispatch' `
  -Headers @{ Authorization = "Bearer $token" }
```

Set the task to avoid starting a second instance while one is running. The API claims jobs before sending to prevent duplicate deliveries if calls overlap.

## Runner setup

Open **Runner Profile → Upcoming run reminders**, enable reminders, choose 30 or 60 minutes, and save. Accept the browser notification permission prompt. The browser can deliver push while the RunPlan tab is closed, subject to the browser/OS notification settings. Local development works on `localhost`; a deployed site must use HTTPS.
