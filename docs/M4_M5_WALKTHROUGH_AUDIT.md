# M4 + M5 Walkthrough Audit — Trystan Live Review

**Prepared:** 2026-07-26 · **Environment:** local dev (verified live, not assumed)
**Legend:** ✅ COMPLETE · 🟡 INCOMPLETE · ⏸️ DEFERRED (to M6) · ⚠️ NEEDS CORRECTION (known issue)

---

## 0. Environment status (verified live)

| Service | Port | Status |
|---|---|---|
| Backend API (Laravel) | 8000 | ✅ 200 — Acutera + roofing brand both resolve |
| Admin frontend (React) | 5173 | ✅ 200 |
| Public website (Next.js) | 3000 | ✅ 200 |

**Test accounts (all verified working, password = `password`):**

| Role | Email | Verified |
|---|---|---|
| Owner | `admin@hsop.com` | ✅ role=owner |
| PM | `pm@hsop.com` | ✅ role=pm |
| Contractor | `contractor@hsop.com` | ✅ role=contractor |
| Customer | `sarah@example.com` | ✅ role=customer |

**Second brand E2E re-verified live just now** (not carried over from last pass):
`example-roofing.test` → chat intake collected all fields → estimate **$11,000–$17,600 CAD** → 40 booking slots offered → **lead #401 created** → **booking confirmed = true**. Fully isolated from Acutera.

**Demo-cleanliness fix applied this pass:** the earlier lead cleanup deleted test leads #224/#398/#399/#400 but left **orphaned `NextAction` rows** behind. The AI Command Center's "stuck leads" tool reads `NextAction`, so it was live-listing those 4 ghost lead IDs (with blank names). Archived + removed the 5 orphaned rows. Command Center now reports only real stuck leads (#75, #76). **This would have been visible and embarrassing during a live Command Center demo — now fixed.**

---

## MILESTONE 4

### M4.1 — Full customer journey (lead → quote → acceptance → job → scheduling → completion → payment → review) — ✅ COMPLETE

Every stage exists end-to-end in code and routes:
- **Lead:** `POST /leads` (`routes/api.php:86`), plus Gmail + public-intake pipelines.
- **Quote:** `LeadController::sendQuote` → `LeadQuoteWorkflowService`; `QuoteController` (`api.php:140–146`).
- **Acceptance:** portal `POST /portal/{token}/accept-quote` (`api.php:52`), authed `POST /quotes/{quote}/approve`.
- **Job creation:** on accept via `LeadQuoteWorkflowService` (applies 80/10/10 split at `:180–182`); also `convert-to-job`.
- **Scheduling / site visits:** `schedule-site-visit`, `jobs/{job}/schedule`, `ScheduleController`, `SiteVisit` model.
- **Completion:** `mark-ready-for-review` → `contractor-complete` → `mark-complete` → `accept-completion` (`api.php:125–128`).
- **Payment:** Stripe Checkout (portal + job), e-transfer, `confirm-payment`.
- **Review:** portal review routes + `ReviewFeedbackController` + `ReviewRequestService`.

**Caveats:**
- Review/feedback persists to table **`review_feedback`** (present, 0 rows locally — nothing submitted yet). No table literally named `reviews`; not a defect, just naming.
- Stale code comment in `JobController.php:789` references a `create-payment-intent` route that doesn't exist; real card path is Stripe Checkout Sessions. Cosmetic only.

### M4.2 — PM dashboard & workflows — ✅ COMPLETE
`DashboardController::pm()` (`:58–188`) → `GET /dashboard/pm/kpis`; UI `frontend/src/pages/PMDashboard.jsx`. Covers contact-needed, site visits, pricing-waiting, quotes, schedule, missing updates, revisions, completion acceptance, feedback follow-up.
**Caveat:** one KPI payload string still says "Stripe transfer still mocked until keys" (`:186`) — accurate locally (no Stripe secret key set), see M4.4.

### M4.3 — Contractor dashboard & workflows — ✅ COMPLETE
`DashboardController::contractor()` (`:191–243`) → `GET /dashboard/contractor/kpis`; price submission (`submit-price`), job leads (`GET /contractor/leads`), completion flow, payouts, docs. UI `frontend/src/pages/ContractorDashboard.jsx`.

### M4.4 — Stripe: payments, Connect, 80/10/10 splits, payouts — ⚠️ NEEDS CORRECTION (config, not code)
**Code is complete:** Checkout Sessions (`StripeCheckoutController` / `StripePaymentProvider:50–94`), Connect onboarding (`StripeConnectController:153–211`), **80/10/10 split** (defaults in migration `milestone3_remaining:47–54`, `PricingService:12–37`, per-job override `PUT /jobs/{job}/split`), payout eligibility + `createTransfer()` (`PayoutEligibilityService:180–295`), hourly scheduled payout processor (`console.php:28–31`).

**Runtime reality on this machine (be honest in the demo):**
- `STRIPE_SECRET` (secret key) is **NOT set locally** → live charges + real transfers will **not** execute here. Webhook secret is present; Connect uses test/fake accounts.
- So locally this is effectively "logic complete, live keys not wired." Splits, payout math, and Connect onboarding UI all work; actual money movement needs prod Stripe keys.

**→ Recommend for the walkthrough:** demo the split configuration + payout eligibility UI and explain money-movement runs on prod keys; do **not** attempt a live card charge locally (it will fail).

### M4.5 — Gmail lead intake — 🟡 INCOMPLETE (code complete, one-time consent still NOT done)
Code is complete: OAuth (`GmailOAuthService`, `prompt=consent`), inbox fetcher (`GmailInboxFetcher`), poll every 5 min (`console.php:11–14`), connect UI in Settings, manual `POST /oauth/gmail/fetch-now`.

**Current live state (checked, not assumed):** `gmail_connected` = not set, `gmail_refresh_token` = none. **Gmail is NOT connected and is NOT flowing leads automatically.** The one-time Google consent step Trystan needed to perform **has not been done** (at least not in this environment). Client ID is present, so the connect button will work — it just hasn't been authorized.
It is **polling**, not push (no Pub/Sub watch) — by design.

### M4.6 — AI Command Center (real OpenAI, accurate answers) — ✅ COMPLETE
Verified with a **live OpenAI call** just now: `POST /command-center/ask` returned an accurate, data-grounded answer using real tools (`get_stuck_leads`, `get_pm_follow_ups`), provider **openai**, model **gpt-4o-mini**, with token + cost tracking (`estimated_cost_usd`). Tools available: today's ops summary, stuck leads, PM follow-ups, jobs ready for payout, owner attention items; actions (draft PM message, create next-action) run behind a confirm step. UI `frontend/src/pages/AiCommandCenter.jsx`.
**Note:** all AI module modes are set to **`suggestion`** (not autonomous) — AI proposes, human confirms. Good posture to state on the call.

### M4.7 — Outstanding M4 loose ends
- **Contractor Stripe onboarding — 🟡 INCOMPLETE (expected):** primary demo contractor "Mike Contractor" (#3) has **no Stripe account / not onboarded**. One PM has a `pending` account; a "Grace Fail" test contractor has a fake `complete` account used to exercise the failure path. So: onboarding flow works, but the demo contractor isn't onboarded — consistent with M4.4 (no live keys locally).
- Gmail consent (see M4.5).

---

## MILESTONE 5

### M5.1 — Full public customer journey (chat → photo → estimate → booking → match → confirmation) — ✅ COMPLETE
`PublicIntakeController` (`routes/public.php:14–41`, throttled + brand-scoped): chat `message()` with **SSE streaming** (`:92–117`, `sseMessage():262–329`), photo upload `POST /api/public/intake/media`, price estimate, availability + hold, submit → lead + booking. Front-end `public-website/src/components/ChatWidget.tsx`. **Just proven live** end-to-end on the roofing brand (lead #401, booking confirmed).

### M5.2 — Pricing rules & range calculator (placeholder status) — ✅ COMPLETE / correctly flagged
Deterministic `PricingRangeEstimator` (engine `pricing_range_v1`, not an "AI estimator"). **All 3 seeded pricing rules verified `is_placeholder = YES`** (Acutera drywall_paint, Acutera insulation, Example Roofing roofing). Column defaults to `true` (migration `milestone5_phase3_pricing_rules:26`); labour assumptions are always flagged placeholder inside the estimator. **Nothing is accidentally presented as final/real pricing.** Real Acutera rates remain blocked on Trystan per `docs/MILESTONE5_PHASE6_LAUNCH.md:6`.

### M5.3 — Second test brand / multi-brand proof — ✅ COMPLETE
`BrandResolver` + `ResolvePublicBrand` middleware (`public.brand`). Two active brands: **Acutera Drywall & Paint** (`acuteradrywall.ca`) and **Example Roofing Co** (`example-roofing.test`). Distinct theme, pricing, availability, matching — all via config/seed, no code branching. Isolation covered by `Phase6HardeningTest::test_full_second_brand_flow_isolated_from_acutera`. Re-verified live this pass.
The roofing brand is **clearly synthetic** (`.test` TLD, "Example Roofing") — cannot be confused with a real client.

### M5.4 — Learning Centre — exactly what is captured — ✅ COMPLETE (capture layer only; no learning/ML engine yet)
**Captured today (tables present & wired):**
- **`estimate_outcomes`** (job/estimate + pricing + labour/materials): `estimate_group_id`, `lead_id`, `job_id`, `brand_id`, `version`, `source_kind`, `service_category`, `price_low/high`, `currency`, `confidence`, `available`, `widened`, `is_placeholder`, `is_current`, `pricing_rule_id`, **`inputs_used`**, **`calculation`**, **`materials_assumptions`**, **`labour_assumptions`**, `reasoning_snapshot`, AI model fields, `estimator_engine`, `estimated_at`, `actor_id`, `supersedes_id`, `reason`.
- **`ai_conversation_logs`** (AI conversation logs): `intake_session_id`, `lead_id`, `turn_number`, `role`, `content`, `tool_calls`, `tool_results`, `ai_provider`, `ai_model`, `created_at`. (11 rows locally.) Retention purge command scheduled.
- **`contractor_performance_events`** (contractor performance): `contractor_id`, `job_id`, `lead_id`, `event_type`, `event_data`, `occurred_at`, via `ContractorPerformanceRecorder`. (0 rows locally — no completed-job events yet.)
- **`lead_photos`** (photos): `lead_id`, `file_url`, `uploaded_by`, `created_at/updated_at`.
- Snapshot API: `LearningSnapshotController` + `JobEstimateSnapshotService`.

**NOT captured / reserved (be precise):**
- **Outcomes vs actuals** (final labour/materials actuals on job completion) — framed as **M6** in the UI (`JobDetail.jsx:988–990`). We store the *estimate-time* assumptions, not the *as-built actuals*.
- `embedding_vector` (reserved, empty) and `environmental_context`/weather (reserved null, no weather API) — placeholders for future ML, **not populated**.
- There is **no learning/ML model consuming this data yet** — this is a clean capture foundation, not an active learning loop.

### M5.5 — Security hardening — ✅ COMPLETE
Rate limits on public intake (`AppServiceProvider:69–111` + `routes/public.php`), response sanitization (strips tools/usage/system leakage: `publicPriceEstimate():370–387`, SSE `:287–291`), CORS (`config/cors.php` + `RefreshBrandCorsOrigins`), prompt-injection hardening (`config/public.php:29–38`: ignore role-override, no data exfil). Covered by `tests/Feature/Phase6HardeningTest.php` (rate limit, data-leak checks, upload validation, CORS, prompt injection, OpenAI 429 fallback, multi-tenant isolation).

### M5.6 — Known deferred items — ⏸️ DEFERRED (cleanly out of scope, NOT half-built)
Repo-wide search confirms **no half-built stubs**:
- **Voice AI** — not present (only Gmail *voicemail-email* text parsing, unrelated).
- **WhatsApp** — not present.
- **Full AI Estimating Engine** — not present; only the deterministic `pricing_range_v1` range calculator.
- **Estimate-import tool** — not present.
- **SEO content system** — not present (no sitemap/SEO content engine in `public-website/`).

All are documented as future scope, with no dead code or partial UI. Safe to state as clean M6 candidates.

---

## FLAGS — fix-now vs correctly-deferred

**Fixed during this prep (was genuinely embarrassing):**
- ✅ Command Center ghost leads (#224/#398/#399/#400) from orphaned `NextAction` rows — removed. Now reports real stuck leads only.

**Worth a heads-up before the call (not broken, just be honest about it):**
- ⚠️ **Stripe is logic-complete but has no live secret key locally** — don't attempt a real card charge in the demo; explain money movement runs on prod keys.
- 🟡 **Gmail intake is NOT connected** — code is ready; the one-time Google consent has not been done in this environment. If Trystan expects auto-flowing email leads, this needs the consent click first.
- 🟡 **Demo contractor not Stripe-onboarded** — expected given no live keys; onboarding flow itself works.

**Optional nicety (low priority):** the two live "stuck leads" are named "Verify217344 OpenAI" / "Verify532105 OpenAI" — obviously test names. They power the Command Center stuck-leads demo, so left in place, but could be renamed to look realistic if you want a cleaner live view.

**Correctly deferred (no action):** Voice AI, WhatsApp, full AI Estimating Engine, estimate-import, SEO content, Learning Centre ML loop + as-built actuals, real Acutera pricing rates.

---

## One-line verdict
**M4 and M5 are feature-complete in code.** The only true gaps are **operational/config, not build**: live Stripe keys, Gmail consent, and real pricing rates — all correctly labeled and expected. One real defect (Command Center ghost leads) was found and fixed during this prep.
