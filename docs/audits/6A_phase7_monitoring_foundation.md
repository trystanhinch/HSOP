# Milestone 6A Phase 7 — Monitoring Foundation (Continuous Monitoring, non-staging)

**Date:** 2026-08-01  
**Scope:** Owner System Health surface, failed-job ops, correlation-ID foundation, AlertDispatcher skeleton with one real trigger.  
**Out of scope:** Staging/fault injection, AI-to-AI evaluation, ledger tables, review/learning gateways, pricing, Sentry/third-party APM, cost caps.

---

## What was built

### 1. Failed job visibility (Owner)
- `GET /api/admin/monitoring/failed-jobs` — paginated, PII-safe summary (no full payload)
- `POST /api/admin/monitoring/failed-jobs/{id}/retry` — wraps `queue:retry` by uuid; **AuditLogged** (`monitoring_failed_job_retry`)
- `DELETE /api/admin/monitoring/failed-jobs/{id}` — dismiss; **AuditLogged** (`monitoring_failed_job_dismiss`)
- Migration creates `failed_jobs` if missing (was configured in `config/queue.php` but never migrated)

### 2. Consolidated health summary
- `GET /api/admin/monitoring/summary?window_hours=24` aggregates existing sources only:
  - `failed_jobs` count
  - SMS/email failures (`status IN failed, provider_unavailable`)
  - `ai_action_logs` where `error IS NOT NULL`
  - `stripe_webhook_events` where `status=failed`
  - `workflow_escalation_logs` fired in window
  - overdue `next_actions` (pending/overdue, `due_at <= now`) — **WorkflowEscalationLog has no unresolved flag**
  - Gmail: `gmail_oauth_tokens.last_fetched_at` (real column updated by `GmailInboxFetcher`)
  - Unacknowledged `alerts` count

### 3. Correlation ID foundation (ARC-07)
- Middleware `AssignCorrelationId` on `api` group: accepts `X-Correlation-Id` or generates UUID; echoes on response; `Log::shareContext(['correlation_id' => …])`
- Exception `respond` callback also attaches the header when auth/exceptions short-circuit the middleware return path
- **Not** retrofitted into outbound Twilio / Resend / Stripe / OpenAI clients this phase

### 4. Alert routing skeleton
- `AlertDispatcher::dispatch($severity, $message, $context)` → `alerts` table + optional Slack via existing `logging.channels.slack` / `LOG_SLACK_WEBHOOK_URL`
- Proof-of-concept trigger: `Illuminate\Queue\Events\JobFailed` → `DispatchAlertOnJobFailed` → severity `high`
- Owner: `GET /api/admin/monitoring/alerts`, `PATCH …/alerts/{id}/acknowledge` (AuditLogged)

### 5. Frontend
- `/system-health` — Owner-only System Health page (summary cards, failed jobs retry/dismiss, alerts acknowledge)
- Nav: HeartPulse icon, after Review Center

---

## Files touched

| Area | Paths |
|------|--------|
| Migrations | `2026_08_01_000001_create_failed_jobs_table_if_missing.php`, `2026_08_01_000002_create_alerts_table.php` |
| Models / services | `Alert.php`, `MonitoringSummaryService`, `FailedJobMonitoringService`, `AlertDispatcher` |
| HTTP | `AdminMonitoringController`, `AssignCorrelationId`, `routes/api.php`, `bootstrap/app.php` |
| Events | `DispatchAlertOnJobFailed`, `AppServiceProvider` listen |
| Config | `config/monitoring.php` |
| Frontend | `SystemHealth.jsx`, `navConfig.js`, `App.jsx`, `AppLayout.jsx` |
| Tests | `tests/Feature/Monitoring/MonitoringFoundationTest.php` |

---

## Test results

**New:** `MonitoringFoundationTest` — **7 passed** (owner gates + audit, summary aggregation, correlation ID, AlertDispatcher ± Slack mock, JobFailed → one alert, acknowledge).

**Full suite:** **374 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`). Delta vs Phase 6 baseline (367): **+7**.

---

## Signals NOT yet wired to alerts (next-phase backlog)

Only **permanent queue `JobFailed`** is wired. Still unwired:
- SMS / email delivery failures (`SmsLog` / `EmailLog`)
- AI action errors (`AiActionLog.error`)
- Stripe webhook `failed` events
- Workflow escalations / overdue next actions
- Gmail fetch failures / stale `last_fetched_at`
- Review/Learning gateway kill-switch engagement
- Scheduler “missed run” detection (no heartbeat table beyond Gmail token timestamp)

## Outbound calls NOT yet correlation-tagged (next-phase backlog)

Request-level ID is set; these providers do **not** yet receive/propagate `X-Correlation-Id` / `correlation_id`:
- Twilio (SMS)
- Resend (email)
- Stripe API + webhook handling
- OpenAI (operational + evaluation)
- Gmail API
- Queue job payload / worker log context (beyond what Monolog inherits if the same process shares context)

## Assumption flags

1. **Gmail last run:** Uses `gmail_oauth_tokens.last_fetched_at` — not a dedicated scheduler heartbeat. Documented in summary JSON `gmail_last_run_note`.
2. **Escalation “unresolved”:** Surfaced via overdue `next_actions` plus escalations-fired count; `workflow_escalation_logs` is fire-only.
3. **Slack:** Reuses `LOG_SLACK_WEBHOOK_URL` / `logging.channels.slack`; no-op when URL empty. Tests mock Slack; never hit a real webhook.
4. **Failed job payload:** Never returned to the Owner UI (PII/secrets risk).
5. **`Log::shareContext`** (not `withContext`) is required for `Log::sharedContext()` visibility in Laravel 12.

---

*End of Phase 7 audit.*
