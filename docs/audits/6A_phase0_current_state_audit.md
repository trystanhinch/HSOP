# Milestone 6A — Phase 0 Current-State & Implementation Readiness Audit

**Date:** 2026-07-30  
**Scope:** Read-only discovery against the ServiceOP codebase (Laravel API + React admin SPA + Next.js public site).  
**Purpose:** Input for a fixed-price 6A proposal. Conservative: only capabilities verified in-repo or by a local test run are stated as existing.  
**Stack note:** `backend/composer.json` requires **Laravel 12** (`laravel/framework: ^12.0`), PHP 8.2+, Sanctum 4.x — not Laravel 11 as sometimes summarized externally.  
**Test snapshot (this audit):** `php artisan test` → **305 passed, 1 failed** (`PublicIntakePhase1Test > unknown domain returns 404` — pre-existing). No PHPUnit coverage report is configured/generated in CI.

---

## 1. Authentication & Identity

### What exists today
- **Primary auth:** Laravel Sanctum personal access tokens. Login issues `createToken('auth_token')` with **no named abilities** (`backend/app/Http/Controllers/Api/AuthController.php`). `User` uses `HasApiTokens` (`backend/app/Models/User.php`).
- **Roles** (MySQL ENUM on `users.role`, migrations `2026_07_09_000001_milestone4_phase1_foundation.php`, `2026_07_26_000001_content_editor_role_and_brand_scope.php`):
  - `owner`, `pm`, `contractor`, `customer`, `ai_super_admin`, `content_editor`
- **Route guards** (`backend/bootstrap/app.php` aliases + `backend/routes/api.php`):
  - `auth:sanctum`
  - `active.user` → `EnsureActiveUser`
  - `role:…` → `RoleMiddleware` (simple `in_array($user->role, $roles)`)
  - `restrict.content_editor` → `RestrictContentEditor`
  - `developer` → `EnsureDeveloper` (owner + `is_developer` flag)
  - Public brand: `public.brand` → `ResolvePublicBrand` (public intake routes)
- **Customer portal:** opaque `leads.customer_portal_token` (64-char random), **not** Sanctum — routes under `/api/portal/{token}` (`CustomerPortalController`, quote view-by-token).
- **AI service identity (partial):** role `ai_super_admin` exists; interactive login is **blocked** (`AuthController` returns 403). Used as actor fallback in `AiActionGate` and permissioned via `AiActionAuthorizer` + `config/ai_permissions.php` / `config/ai_actions.php`. Seeded in `Milestone4Seeder`. Hidden from normal user listings (`UserController`).
- **PM brand scope:** `PmAuthorizationService` + `pm_brand_assignments` (audit batch PM-01/PM-02).
- **No** Sanctum `tokenCan` / ability-scoped tokens in application code (abilities column exists on `personal_access_tokens` but is unused).

### What is reusable as-is
- Role middleware + Sanctum bearer pattern for human operators.
- `ai_super_admin` as a **conceptual** non-interactive AI actor (login already forbidden).
- `AiActionAuthorizer` allow/forbid lists for AI-capable actions.
- Command Center query tools as a pattern for **permissioned read tools** (`CommandCenterQueryService`).

### What requires modification
- Minting long-lived / machine tokens with **explicit Sanctum abilities** (read-only review scopes).
- New middleware or ability checks distinct from human `role:` lists so External Review AI cannot call write routes.
- Possibly separating “system AI actor” from Command Center human sessions.

### What is completely net-new
- Dedicated “External Review AI” identity productization (credential issuance, rotation, IP allowlists, rate limits, tool ACL matrix for 6A gateway).
- Request signing / mTLS / OAuth client-credentials for third-party review providers (none present).

### Complexity
**Medium** — identity primitives exist; scoped machine auth and hard read-only enforcement do not.

### Owner Input Needed
- Whether Trystan wants External Review AI to reuse `ai_super_admin` or a new role/token type.
- Whether review traffic must be isolated to staging only (recommended; not enforced today).

---

## 2. Logging & Audit Trail

### What exists today
| Store / model | Purpose |
|---------------|---------|
| `AuditLog` | Generic user/object action audit (`audit_logs`) — used by company identity, PM auth denials, payment destinations, messaging, etc. |
| `ActivityTimelineEntry` | Lead/job timeline events (`activity_timeline_entries`) via `ActivityTimelineService` |
| `AiActionLog` | AI decisions, modes, tokens, cost, `trace_id`, idempotency, outcomes (`ai_action_logs`) |
| `AiConversationLog` | Public intake conversation turns + tool_calls/results + `trace_id` |
| `IntakeAuditLog` | Gmail/intake quarantine decisions |
| `SmsLog` / `EmailLog` | Notification delivery with status, error, retry (`DeliveryRetryService`) |
| `StripeWebhookEvent` | Stripe webhook receipt/processing record |
| `WorkflowEscalationLog` | Escalation sweep outcomes |
| `CustomerMergeLog` / `LeadMergeLog` | Dedup merges |
| `PricingOverrideLog` | Pricing override capture |
| `PayoutEvent` | Payout lifecycle events |
| `AiOpsReport` | Scheduled daily/weekly AI/ops reports (`ops:generate-report`) |
| `ContentRevision` | Brand content revision history |

- **AI trace IDs:** `trace_id` is generated per AI action/conversation turn (`AiActionGate`, `CommandCenterService`, conversational providers). Filterable via `AiSettingsController` (`GET /api/ai/action-logs?trace_id=`).
- **No** end-to-end correlation ID middleware spanning HTTP → queue → Twilio/Resend/Stripe for *all* requests. SMS/email logs are keyed by trigger/job/user, not a universal request ID.
- **App logs:** Monolog to `storage/logs/laravel.log` (`config/logging.php`). Slack channel optional via `LOG_SLACK_WEBHOOK_URL`. No Sentry/Bugsnag/Datadog package in `composer.json`.
- **phpunit.xml** disables `TELESCOPE_ENABLED`, `PULSE_ENABLED`, `NIGHTWATCH_ENABLED` — packages are **not** installed as dependencies.

### What is reusable as-is
- `AiActionLog` + `trace_id` for AI review sessions.
- Owner UI surfaces for AI logs (`/api/ai/action-logs`, conversation logs).
- SMS/Email log + retry for notification failures.
- `AuditLog` for human mutations.

### What requires modification
- Propagate a correlation ID across all API requests and outbound provider calls.
- Standardize what External Review AI may read from these tables (PII redaction).

### What is completely net-new
- Centralized error aggregation (Sentry or equivalent).
- Cross-service distributed tracing.
- Immutable, append-only “review session” audit specific to 6A gateway (query/tool call ledger for external AI).

### Complexity
**Medium** for correlation + review ledger; **High** if full observability stack is in scope.

---

## 3. Data Access Patterns

### What exists today (core domain models)
Primary Eloquent models under `backend/app/Models/` (73 files). High-level graph:

- **Lead** → Customer, assigned PM (`assigned_pm_id`), site-visit contractor, Quotes, SiteVisits, Messages, Photos, portal token, intake fields, quarantine linkage.
- **Job** → Lead, Customer, PM, Contractor (user id), Quotes/Invoices/Payments/Payouts, Messages, JobUpdates, Revisions, Schedule fields.
- **Quote** / **QuoteItem** → Lead/Job, lifecycle statuses (A-32).
- **SiteVisit** / **SiteVisitSubmission** / photos → Lead, PM, Contractor; assignment lifecycle fields (CT-07).
- **Contractor** (profile) ↔ **User** (role contractor); documents, availability (CT-09), compliance (CT-03).
- **Customer** → validation/dedup (A-33), communication consent.
- **Message** → job/lead threads, delivery metadata (PM-04).
- **Invoice** / **Payment** / **Payout** / **FinancialLedgerEntry** / **PaymentDestination** (A-01/A-03).
- **Booking** / **BookingHold** / **AvailabilityWindow** / **SlotClaim** / **PmMeeting** — scheduling (A-31 calendar).
- **AI:** `AiCommandSession`, `AiCommandMessage`, `AiCommandSavedQuery`, `AiActionLog`, `AiConversationLog`, `AiActionType`, `AiOpsReport`.
- **Brand / CMS:** `Brand`, `BrandPage`, SEO overrides, `CompanySource`, content revisions.
- **Learning:** `EstimateOutcome` (includes nullable `embedding_vector` **reserved, always null** in M5).

**Serializers / API Resources:** essentially **one** Laravel Resource — `backend/app/Http/Resources/QuoteResource.php`. Controllers overwhelmingly return ad-hoc arrays / `toArray()` / query maps. Closest “read tool” pattern is `CommandCenterQueryService` (hard-coded tools: today ops summary, stuck leads, PM follow-ups, payout-ready jobs, owner attention items).

**Search:** `JobController::search` uses conventional SQL filtering — **no** Scout, Meilisearch, OpenSearch, or vector index in use. `embedding_vector` on estimate outcomes is explicitly unused (`EstimateOutcomeRecorder` unsets/nulls it).

### What is reusable as-is
- Eloquent domain model as source of truth.
- Command Center query-tool pattern for governed reads.
- `productionOnly()` / `is_test_data` scopes (A-05) for excluding test records.

### What requires modification
- Build stable, versioned read DTOs (or expand Resources) for reviewer tools — current ad-hoc JSON is fragile for an external contract.
- Enforce read-only tool surface (no accidental write controller reuse).

### What is completely net-new
- Semantic / vector search for review (if 6A requires it).
- Full-text index strategy beyond existing LIKE/filter endpoints.
- Dedicated “review snapshot” export (sanitized lead/job packet).

### Complexity
**Medium–High** for a clean read-tool API; **High** if semantic search is required.

---

## 4. Staging Environment

### What exists today (from repo evidence)
- **Production DO App Platform** is documented and live:
  - API: `https://api.serviceop.ca`
  - Admin SPA: `https://serviceop-vbstp.ondigitalocean.app`
  - Docs: `PRODUCTION_DEPLOYMENT_HANDOFF.md`, `docs/MILESTONE5_PHASE6_LAUNCH.md`
- **Git branch** `milestone-5-dev` used as pre-main integration; deploy repo `newgh` → DigitalOcean.
- **No** App Spec / `app.yaml` / Terraform in-repo defining a separate **staging** App Platform app + DB.
- Historical mention of a prior staging MySQL (`hsop_job_command-313931a52b`) in handoff — not an active resettable staging product in code.
- **Seed / reset-ish tooling (not a staging product):**
  - Seeders: `DemoSeeder`, `Milestone4Seeder`, `SettingsSeeder`, `MessageTemplateSeeder`
  - Secret-gated `DeployController` routes (`backend/routes/deploy.php`): migrate, seed, setup, `clean-test-data`, repair, Stripe probes
  - Owner APIs: `/api/admin/test-data/*` flag test data (A-05) — **flags**, does not wipe/rebuild a staging DB
- **Provider switching by env (implemented):**
  | Concern | Config | Switch |
  |---------||--------|--------|
  | Payments | `config/payment.php` | `PAYMENT_PROVIDER=mock\|stripe` + Stripe test/live keys |
  | AI classify | `config/ai.php` | `AI_PROVIDER=mock\|openai` |
  | AI chat | `config/ai.php` | `AI_CONVERSATIONAL_PROVIDER=mock\|openai` |
  | SMS | `config/services.php` | `SMS_ENABLED`, Twilio creds |
  | Email | `config/mail.php` + Resend | `MAIL_MAILER` (example defaults to `resend`), `RESEND_API_KEY` |
- Tests force `payment.provider=mock` / `ai.provider=mock` in many Feature tests.

### What is reusable as-is
- Env-based mock vs live providers — essential for safe staging.
- Seeders + deploy migrate/seed endpoints as building blocks.
- Test-data flagging to keep prod analytics clean.

### What requires modification
- Deploy secret URL surface is powerful and dangerous; staging reset must not rely on production `DEPLOY_SECRET` routes without isolation.
- Documented “watch DO logs” is not a staging reset pipeline.

### What is completely net-new
- Dedicated staging App Platform app(s) + **separate** managed MySQL.
- Automated **resettable** staging (snapshot restore or migrate+seed+anonymize).
- Guaranteed outbound sink (Twilio test, Resend sandbox, Stripe test) with fail-closed if prod keys detected.
- Data anonymization / subset copy from production (none today).

### Complexity
**High** (infra + process); application hooks are only **Medium**.

### Owner Input Needed
- Does a DO staging app already exist outside this repo? (Cannot confirm from codebase alone.)
- Is production DB ever cloned to non-prod today? By whom?
- Preferred reset cadence and whether real customer PII may appear in staging.

---

## 5. Test Coverage

### What exists today
- **Runner:** PHPUnit 11 (`phpunit/phpunit`), **not Pest**. Suites: `tests/Unit`, `tests/Feature` (`phpunit.xml`).
- **Inventory:** ~**48** `*Test.php` files; ~**287** Feature + ~**19** Unit = ~**306** `test_*` methods.
- **Latest full run (this audit):** **305 passed, 1 failed** (same pre-existing PublicIntake brand-domain 404 expectation).
- **Coverage %:** **Not available.** `phpunit.xml` includes `<source><include>app</include></source>` but no coverage driver/report/CI job is defined in-repo. Do not claim line coverage.
- **Browser/E2E:** Playwright present for admin SPA (`frontend/playwright.config.js`, `frontend/tests/self-verify.spec.js`, `mobile-actions.spec.js`). Public site lockfile also references Playwright. **No** Laravel Dusk / Cypress. Playwright suite is thin and not wired as a required CI gate in this repo.

### Module coverage (any automated tests vs none)

| Module | Coverage today? | Representative tests |
|--------|-----------------|----------------------|
| Lead intake (Gmail + pipeline) | **Yes** | `LeadIntakeTest`, `GmailIntakeQuarantineTest`, unit parser/classifier |
| Public website intake | **Yes** (1 known fail) | `PublicIntakePhase1/2Test`, talk/transcribe tests |
| Pricing / quote engine | **Yes** | `PricingRangePhase3Test`, `PricingSourcesA20A25Test`, `ReportsQuoteLifecycleA26A32Test` |
| Stripe / Connect / payouts | **Yes** | `StripeIntegrationTest`, `FinancialLedgerA01Test`, `PaymentDestinationA03Test`, `Phase4AccountingTest` |
| Contractor matching / profiles | **Yes** | `ContractorMatchingPhase5Test`, `ContractorAuthoritativeProfileA04Test`, CT portal tests |
| AI Command Center | **Yes** | `CommandCenterTest`, `AiGovernanceA17A18A27Test` |
| Customer portal / reviews | **Yes** | `PortalTokenReviewStripeRegressionTest`, `QuotePortalTokenRegressionTest`, `Phase5ReviewsTest` |
| Scheduling / calendar | **Yes** | `AvailabilityBookingPhase4Test`, `OpsSchedulingMessagingA15A24A30A31Test` |
| Brand / CMS / identity | **Yes** | `BrandIdentityA06A22Test`, `BrandContentWorkflowA36Test`, `ContentEditorAccessTest` |
| Messaging templates / channels | **Yes** | `MessagingTemplatesChannelsA16A19A21Test` |
| Learning Centre capture | **Yes** | `LearningDataFoundationTest`, `LearningCentreFlaggedItemsTest` |
| Continuous monitoring / AI-to-AI eval | **None** | — |
| External review gateway | **None** | — |
| Staging reset automation | **None** | — |

### What is reusable as-is
- Broad Feature suite as regression baseline for 6A changes.
- Mock AI/payment providers for deterministic tests.

### What requires modification
- Fix or re-scope the PublicIntake 404 failure before treating suite as fully green.
- Expand Playwright beyond smoke self-verify if 6A requires UI review evidence.

### What is completely net-new
- AI-to-AI evaluation harness, golden transcripts, continuous monitoring tests.
- Coverage reporting in CI.
- Staging reset integration tests.

### Complexity
**Medium** to extend; **High** for a new evaluation/monitoring layer.

---

## 6. AI / OpenAI Integration

### What exists today
**Config:** `backend/config/ai.php` — default model `gpt-4o-mini` via `OPENAI_MODEL`; Whisper + realtime transcription model envs; cost rates for logging.

**Service classes calling OpenAI (or abstracting it):**
| Class | Role |
|-------|------|
| `App\Services\Ai\OpenAiProvider` | Lead classify (`chat/completions`) |
| `App\Services\Ai\OpenAiConversationalProvider` | Public intake conversational agent + tools |
| `App\Services\Ai\OpenAiTranscriptionService` | Whisper audio transcription |
| `App\Services\Ai\OpenAiRealtimeTalkService` | Realtime talk session |
| `App\Services\Ai\MockAiProvider` / `MockConversationalAiProvider` | Deterministic fallbacks |
| `App\Services\Ai\AiActionGate` | Mode/risk/approval gate + `AiActionLog` |
| `App\Services\CommandCenter\CommandCenterService` | Owner/PM Command Center ask loop |
| `App\Services\CommandCenter\CommandCenterQueryService` | Read tools for Command Center |
| `App\Services\CommandCenter\CommandCenterActionService` | Confirmable write actions |
| `App\Http\Controllers\Api\WorkflowAssistController` | Call-prep / draft-message / quote-prep assists |

**Prompts:**
- Mostly **in code / config**, not a prompt CMS: e.g. classify system string hardcoded in `OpenAiProvider`; conversational system prompt in `config/public.php` (`conversational_system_prompt`) with `BrandPromptTemplate` variables; overridable via `PUBLIC_CONVERSATIONAL_SYSTEM_PROMPT` env.
- `prompt_version` field exists on `AiActionLog` but versioning discipline is incomplete across call sites.

**Logging of AI I/O:**
- `AiActionLog`: model, tokens, cost, latency, data_viewed, decision, error, `trace_id`.
- `AiConversationLog`: roles, content (reveal gated), tool_calls/results, provider/model.
- Kill switch + simulation mode via Settings (`AiActionAuthorizer` / `AiActionGate`).
- Scheduled `ops:generate-report` + `AiOpsReport`.

**Routes (examples):** `/api/command-center/*`, `/api/ai/actions/*`, `/api/ai/settings`, `/api/ai/action-logs`, `/api/ai/conversation-logs`, public intake talk/transcribe under public routes.

### What is reusable as-is
- Provider interface + mock/openai switch.
- Action gate, permissions, Command Center tool loop — closest existing “governed AI” substrate.
- Conversation/action logging as evidence store.

### What requires modification
- Externalize/version prompts for review reproducibility.
- Ensure every OpenAI call path logs consistently (transcription/realtime vs chat).
- Tighten that Command Center tools cannot be invoked by an external reviewer without a new ACL.

### What is completely net-new
- Read-only **external** review gateway (third-party model reviewing ServiceOP).
- AI-to-AI evaluation (grader model vs system under test).
- Continuous monitoring of AI quality/regressions.

### Complexity
**High** for gateway + evaluation; existing internal AI is already substantial (**reuse Medium**).

### Owner Input Needed
- Target external review provider (OpenAI-only vs multi-vendor / “Atlas”).
- Whether Command Center autopilot modules may remain on in staging during reviews.

---

## 7. Secrets & Configuration

### What exists today
- **Intended production approach:** DigitalOcean App Platform environment variables (documented in `PRODUCTION_DEPLOYMENT_HANDOFF.md`, launch checklist). Local: `backend/.env` (gitignored).
- **`.gitignore` explicitly excludes:** `backend/.env`, `backend/.env.*` (keeps `.env.example`), rotation packets `backend/.secrets-rotation-*.env`, inventory tmp JSON files.
- **Tracked config templates:** `backend/.env.example` (empty secret placeholders), `frontend/.env.production` (**public URLs only** — `VITE_API_URL` / `VITE_STORAGE_URL`).
- **No** HashiCorp Vault / AWS Secrets Manager / DO Secrets Manager client in application code.
- **Sensitive deploy surface:** `DEPLOY_SECRET` gate on `/deploy/*` routes (`DeployController`) — operational convenience; high impact if leaked.

### Rotated / leaked secret check (conservative)
| Check | Result |
|-------|--------|
| `git grep` over tracked source for live-looking `sk_live_`, long `sk_test_51…`, `sk-proj-…`, `whsec_…`, long `base64:` APP_KEYs | **No matches** in tracked application files |
| Local gitignored `.env` / `.env.production` / `.secrets-rotation-PENDING.env` | Present on developer machines (expected); **must not be committed** |
| Demo passwords in `DemoSeeder` (`password`) | Intentional demo accounts — not production secrets |
| Prior chat/agent transcripts / untracked `tmp-*.json` | **Outside git**; may still contain historical operational dumps — treat as Owner hygiene, not “in codebase” |

**Conclusion:** Tracked repository content appears **free of live provider secrets** at audit time. Cannot cryptographically prove “never existed in any historical commit object on every remote” without a dedicated secrets-scanning pass (e.g. `gitleaks` / GitHub secret scanning) — **Owner Input Needed** to confirm enterprise secret scanning is enabled on `usmantsz/ServiceHOP` and that rotated keys were invalidated at providers.

### What is reusable as-is
- Env-based config pattern and gitignore discipline.
- Provider mock switches for tests.

### What requires modification
- Reduce reliance on long-lived `DEPLOY_SECRET` HTTP endpoints in production.
- Formalize staging vs prod secret sets (fail closed).

### What is completely net-new
- Managed secret store integration (if required by 6A).
- Automated secret scanning in CI.
- Reviewer-specific credentials with short TTL.

### Complexity
**Low–Medium** for process; **Medium** if hardening deploy routes + CI scanning.

---

## 8. Monitoring / Alerting Today

### What exists today
- **Laravel health route:** `/up` (`bootstrap/app.php`).
- **In-app operational visibility (owner):**
  - SMS/Email log UIs + retry (`SmsLogController`, `EmailLogController`)
  - AI action / conversation logs + settings kill switch
  - Stripe webhook event table + deploy probe endpoints
  - `AiOpsReport` daily/weekly generation (`routes/console.php` schedules)
  - Workflow escalation sweep (`workflow:escalation-sweep`)
  - Admin developer DB overview (developer-gated)
- **Queues:** default `QUEUE_CONNECTION=database` (`.env.example`); `failed_jobs` table configured in `config/queue.php`. **No Horizon.** Failed jobs are not presented via a first-class Owner UI found in routes.
- **Schedulers** (must run on App Platform worker/cron — **Owner Input Needed** to confirm production scheduler process): Gmail fetch, escalations, ops reports, payouts, intake cleanup, booking holds, learning log purge.
- **Docs** ask ops to watch App Platform logs / DO log drain / 5xx spikes (`docs/MILESTONE5_PHASE6_LAUNCH.md`) — checklist items, not implemented alerting rules in-repo.
- **Not present in composer require:** Sentry, Bugsnag, Datadog, New Relic, Horizon, Telescope.

### How failures surface to the owner (today)
| Failure type | Surfacing |
|--------------|-----------|
| Failed SMS/email | `sms_logs` / `email_logs` status + Owner retry APIs |
| Stripe webhooks | `StripeWebhookEvent` + deploy diagnostic routes |
| AI errors | `AiActionLog.error`, Command Center / AI settings UIs, ops reports |
| Escalations overdue | NextAction + escalation sweep / Command Center tools |
| Queue `failed_jobs` | DB table / artisan — **weak Owner UX** |
| App 5xx | DO App Platform metrics/logs (platform-native; not coded) |

### What is reusable as-is
- Delivery logs + AI logs + ops reports as monitoring seeds.
- `/up` for basic uptime probes.

### What requires modification
- Wire platform alerts (5xx, queue depth) to Owner notification channels.
- Expose failed-job visibility in admin UI or alerts.

### What is completely net-new
- Continuous AI quality monitoring (6A).
- SLO dashboards, on-call paging, synthetic transaction checks beyond Playwright smoke.
- Automated alert on external-review gateway anomalies.

### Complexity
**High** for continuous AI monitoring; **Medium** for basic uptime/error/queue alerts.

### Owner Input Needed
- Current DO alert policies (if any).
- Whether a queue worker and scheduler are confirmed running in production App Platform.

---

## Summary Table

| 6A Component | Reuse % | New Build Required | Complexity | Key Risk |
|--------------|---------|--------------------|------------|----------|
| Governed External Review AI identity & auth | ~35% | Scoped machine tokens, read-only ACL, gateway auth | Medium | Accidental write access via human role routes |
| Read-only review tools / data packets | ~40% | Versioned DTOs; tool registry beyond Command Center | Medium–High | PII leakage; unstable ad-hoc JSON contracts |
| Audit / correlation for review sessions | ~45% | Universal request ID; immutable review ledger | Medium | Incomplete forensic trail across Twilio/Stripe |
| Resettable staging environment | ~15% | Separate DO app+DB; reset/anonymize pipeline; fail-closed keys | High | Shared prod DB or prod Twilio/Stripe keys in “staging” |
| Provider sandbox switching | ~70% | Enforce staging policy; detect live keys | Low–Medium | Misconfigured env silently hits live providers |
| Automated test / quality harness expansion | ~50% | AI-to-AI eval, coverage CI, stronger E2E | High | False confidence from Feature suite without coverage % |
| Existing gpt-4o-mini integration (internal) | ~80% | Prompt versioning consistency | Low–Medium | Not a substitute for *external* review gateway |
| External AI-to-AI evaluation layer | ~5% | Graders, fixtures, scoring, regression gates | High | Undefined success criteria without Owner rubric |
| Continuous monitoring & alerting | ~20% | Error/queue/AI-quality alerts; dashboards | High | Failures only visible if Owner inspects logs UIs |
| Secrets hygiene & reviewer credentials | ~60% | CI secret scan; short-lived reviewer creds; deploy route hardening | Medium | Historical leaks outside tracked tree; `DEPLOY_SECRET` surface |

---

## Owner Input Needed (consolidated)

1. Confirm whether a **DigitalOcean staging app + separate database** already exists (not visible in repo).  
2. Confirm production **scheduler + queue worker** processes on App Platform.  
3. Confirm **secret scanning** on GitHub/DO and that all rotated credentials were revoked at Twilio/Resend/Stripe/OpenAI/Google.  
4. Choose External Review identity model: extend `ai_super_admin` vs new token/role.  
5. Confirm target external review stack (OpenAI-only vs other “Atlas”/multi-model).  
6. Staging data policy: anonymized subset vs synthetic-only.  
7. Attach/clarify full Milestone 6A PDF acceptance criteria if any requirements above were mis-mapped.

---

## Explicit non-claims

- No architecture or implementation proposed in this document.  
- No hour or dollar estimates.  
- No assertion that Playwright E2E or PHPUnit coverage % currently gates production deploys.  
- No assertion that staging isolation exists until Owner confirms infra outside this repository.
