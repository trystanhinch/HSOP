# Milestone 6A Phase 10 — Alert Wiring & Correlation Propagation Completion

**Date:** 2026-08-01  
**Scope:** Wire remaining Phase 9 alert signals into existing `AlertDispatcher`; propagate request correlation IDs onto Twilio / Resend / Stripe / OpenAI call paths (or ServiceOP log tables as fallback).  
**Builds on:** Phase 7/9 `AlertDispatcher`, `AssignCorrelationId`, `DispatchAlertOnJobFailed` — **not rebuilt**.  
**Out of scope:** Staging/fault injection, third-party APM, new providers, ledger/gateway/pricing/least-privilege changes.

---

## What was built

### Task 1 — Remaining alert signals

| # | Signal | Hook | Severity | Source key |
|---|--------|------|----------|------------|
| 1 | SMS delivery failure | `SmsLogMonitoringObserver` → `DispatchAlertOnSmsDeliveryFailed` on create / status→failed\|provider_unavailable | medium | `sms.delivery_failed` |
| 1b | Email delivery failure | `EmailLogMonitoringObserver` → `DispatchAlertOnEmailDeliveryFailed` | medium | `email.delivery_failed` |
| 2 | AI action error | `AiActionLogMonitoringObserver` → `DispatchAlertOnAiActionError` when `error` set (once per row) | medium | `ai.action_error` |
| 3 | Stripe webhook failure | `StripeWebhookEventMonitoringObserver` → `DispatchAlertOnStripeWebhookFailed` | high | `stripe.webhook_failed` |
| 4 | Workflow escalation fired | `WorkflowEscalationLogMonitoringObserver` → `DispatchAlertOnWorkflowEscalation` | `meta.severity` if present, else `high` for stage=`escalation`, else `medium` | `workflow.escalation_fired` |
| 5 | Gmail intake staleness | `monitoring:check-gmail-staleness` (every 15m) via `GmailStalenessMonitor`; flag `gmail_oauth_tokens.staleness_alerted` clears on successful fetch | high | `gmail.poll_stale` |
| 6 | Kill-switch ON | `DispatchAlertOnKillSwitchEngaged` from review + learning admin `updateKillSwitch` when `enabled=true` only | high | `gateway.kill_switch_engaged` |

Still wired from Phase 7: permanent queue `JobFailed` → `DispatchAlertOnJobFailed` (`queue.job_failed`).

Config: `monitoring.gmail_staleness_hours` (default **2**, env `MONITORING_GMAIL_STALENESS_HOURS`).

### Task 2 — Outbound correlation ID

| Provider | Approach |
|----------|----------|
| **Twilio** | No Message metadata API — stamp `sms_logs.correlation_id` (observer + `CorrelationId::current()`); log context on send attempt |
| **Resend** | `X-Correlation-Id` Symfony header on Mailables via `EmailService::withCorrelationHeader`; stamp `email_logs.correlation_id` |
| **Stripe** | `correlation_id` merged into Checkout session / PI / Connect account / transfer `metadata` via `StripePaymentProvider::withCorrelationMeta` |
| **OpenAI** | Chat Completions has no durable metadata — stamp new sibling column `ai_action_logs.correlation_id` (does **not** replace `trace_id`); auto-set on create |

Helper: `App\Support\CorrelationId::current()` reads `Log::sharedContext()['correlation_id']`.

---

## Files touched

| Area | Paths |
|------|--------|
| Migration | `2026_08_01_000010_phase10_alert_correlation_columns.php` (`correlation_id` on sms/email/ai logs; `staleness_alerted` on gmail tokens) |
| Support / config | `CorrelationId.php`, `config/monitoring.php` |
| Listeners | `DispatchAlertOnSmsDeliveryFailed`, `…EmailDeliveryFailed`, `…AiActionError`, `…StripeWebhookFailed`, `…WorkflowEscalation`, `…KillSwitchEngaged` |
| Observers | `SmsLogMonitoringObserver`, `EmailLogMonitoringObserver`, `AiActionLogMonitoringObserver`, `StripeWebhookEventMonitoringObserver`, `WorkflowEscalationLogMonitoringObserver` |
| Gmail | `GmailStalenessMonitor`, `MonitoringCheckGmailStalenessCommand`, `GmailInboxFetcher` (clears flag), `routes/console.php` |
| Controllers | `AdminReviewGatewayController`, `AdminLearningGatewayController` (kill-switch ON → alert) |
| Outbound | `SmsService`, `EmailService`, `StripePaymentProvider` |
| Models | `SmsLog`, `EmailLog`, `AiActionLog`, `GmailOauthToken` fillable/casts |
| Provider | `AppServiceProvider` observer registration |
| Tests | `tests/Feature/Monitoring/AlertAndCorrelationCompletionTest.php` (10 tests) |

---

## Test results

**New:** `AlertAndCorrelationCompletionTest` — **10 passed**  
(6 signal triggers + Gmail episode semantics + kill-switch ON/OFF + correlation on all 4 providers + Sms/Email service stamp paths).

**Prior monitoring:** `MonitoringFoundationTest` — **7 passed**.

**Full suite:** **392 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`).

| Baseline | Passed | Delta |
|----------|--------|-------|
| Phase 9 | 382 | — |
| Phase 10 | 392 | **+10** |

---

## Phase 9 backlog status

### Signals NOT yet wired to alerts — **mostly cleared**

| Phase 9 item | Status |
|--------------|--------|
| SMS / email delivery failures | **Wired** |
| AI action errors | **Wired** |
| Stripe webhook `failed` | **Wired** |
| Workflow escalations | **Wired** |
| Gmail fetch failures / stale `last_fetched_at` | **Wired** (staleness; not every per-message fetch error) |
| Review/Learning kill-switch engagement | **Wired** (ON only) |
| Scheduler “missed run” beyond Gmail | **Still open** (no generic heartbeat table) |
| AiOpsReport anomalies | **Still open** (daily/weekly reports not pushed to AlertDispatcher) |

### Outbound calls NOT yet correlation-tagged — **primary four done; leftovers noted**

| Phase 9 item | Status |
|--------------|--------|
| Twilio (SMS) | **Done via SmsLog + log context** (SDK has no metadata map) |
| Resend (email) | **Done via header + EmailLog** |
| Stripe API | **Done via metadata** on checkout / account / transfer |
| OpenAI | **Done via `ai_action_logs.correlation_id`** (SDK has no request metadata) |
| Gmail API | **Still open** (not in Phase 10 task list of four providers) |
| Queue job payload / worker log context | **Still open** (request middleware only; workers may lack shared context) |

---

## Assumption flags

1. WorkflowEscalationLog has **no severity column** — severity derives from `meta.severity` or `stage`.
2. Twilio Message create cannot carry custom metadata; local `sms_logs.correlation_id` is the accepted fallback.
3. OpenAI Chat Completions likewise — `correlation_id` column is sibling to `trace_id`, not a replacement.
4. Gmail staleness is one alert per mailbox episode (`staleness_alerted`), cleared when `last_fetched_at` is refreshed by `GmailInboxFetcher`.
5. DeliveryRetryService exhaustion creates a new failed SmsLog/EmailLog row → one alert per failed row (matches “once per log row”).

---

*End of Phase 10 audit.*
