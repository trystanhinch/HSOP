# 6B Evidence Reconciliation & M5 Field Workflow Audit

**Date of evidence run:** 2026-08-01T21:43:14Z (UTC) / 2026-08-02 ~02:43 Asia/Karachi  
**Discipline:** Read-only verification — no application code changed for this document.  
**Method:** Same standard as `docs/audits/6A_signoff_evidence_bundle.md` — real commands, real output, no fabrication.

---

## PART 1 — Test evidence reconciliation

### 1.1 Git HEAD

```
git log --oneline -20
```

| # | Hash (short) | Subject (truncated) |
|---|--------------|---------------------|
| HEAD | `7172410` | Add CT-05 through CT-12 remaining Contractor portal findings… (audit remediation batch 23 — FINAL) |
| | `20d681d` | Add PM-05 through PM-15… |
| | `f577a8a` | Add A-36 Brand Content… |
| | … | (through `bf2c32c` PM-01/PM-02) |

**Full HEAD hash:** `71724104853c8ce6ffcc767fd360c6823117b7a5`  
**HEAD commit date:** `2026-07-30 02:09:34 +0500`

**Critical honesty note (defensible under scrutiny):**  
`git status` at evidence time showed **`main` ahead of `origin/main` by 24 commits**, but **Milestone 6A/6B work (Learning AI, eligibility, learning_records, least-privilege verify, audit docs, etc.) is predominantly uncommitted in the working tree** (modified + untracked).  

Therefore:

| Claim | Truth |
|-------|--------|
| Commit at `git rev-parse HEAD` | `7172410…` (July 30 CT-05–CT-12 batch) |
| Code exercised by `php artisan test` below | **Working tree** (includes uncommitted 6A/6B + DB least-privilege) |
| Can suite counts be attributed to HEAD alone? | **No** — suite reflects working tree, not a single committed SHA for 6B |

Audit phase docs (6B Phase 0–5, least-privilege) also **predate reliable commit tracking** for those features because they were never committed at the time of this reconciliation.

### 1.2 Full suite run (exact)

**Command:** `cd backend; php artisan test`  
**Saved log:** `tmp-suite-evidence-20260802.txt`  
**Terminal end timestamp:** `2026-08-01T21:43:14.279Z`  
**Duration (PHPUnit):** `140.37s`

**Exact PHPUnit footer:**

```
Tests:    1 failed, 417 passed (2512 assertions)
Duration: 140.37s
```

**Collected / outcomes (no rounding):**

| Metric | Count | Notes |
|--------|------:|-------|
| Tests listed (`php artisan test --list-tests`, lines matching `^ - `) | **418** | Matches 417 + 1 |
| Passed | **417** | Exact |
| Failed | **1** | Exact |
| Skipped (this run) | **0** | PHPUnit footer lists no skipped |
| Incomplete | **0** | Not reported |
| Risky | **0** | Not reported |
| Assertions | **2512** | Exact |

### 1.3 Exhaustive skip / exclude inventory

**`phpunit.xml`:** no `<exclude>`, no skip groups found.

**`markTestSkipped` / `@skip` / `markTestIncomplete` / `#[Skip]` search under `backend/tests`:**

| File | Lines | Condition |
|------|------:|-----------|
| `tests/Feature/DbLeastPrivilegeVerifyTest.php` | 28, 44, 58, 79 | `markTestSkipped('MySQL only')` if driver ≠ mysql |
| Same file | 91 | `markTestSkipped('Cannot CREATE USER…')` if `CREATE USER` throws |

**No other** `markTestSkipped`, `@skip`, `markTestIncomplete`, or `#[Skip]` hits in the suite.

**This run:** all five `DbLeastPrivilegeVerifyTest` methods **passed** (scratch `CREATE USER` succeeded) — therefore **0 skipped**.

**Conditional skip sites Trystan should still treat as “can be excluded” on other environments:** the five markTestSkipped call sites above (especially CREATE USER on locked-down managed MySQL).

Unrelated name collision (not a PHPUnit skip): test method display name containing the word “skipped” — e.g. `ContractorAuthoritativeProfileA04Test` → `7b pm and company payouts skipped for manual review` — this is a **passing** test about payout behaviour, not a skipped test.

### 1.4 Pre-existing failure identity (unchanged, unrelated to 6B)

**Class/method:** `Tests\Feature\PublicIntakePhase1Test::test_unknown_domain_returns_404`

**Exact failure message from this run:**

```
FAILED  Tests\Feature\PublicIntakePhase1Test > unknown domain returns 404
Expected response status code [404] but received 200.
Failed asserting that 200 is identical to 404.

at tests\Feature\PublicIntakePhase1Test.php:66
```

**Why unrelated to 6B:** public intake brand-domain resolution (`POST /api/public/intake/start` with unknown host). No Learning AI / eligibility / learning_records / least-privilege involvement. Same failure cited in 6B Phase 1–5 and 6A audit docs.

### 1.5 Evidence table

| Commit hash (HEAD) | Total collected | Passed | Failed | Skipped | Incomplete | Risky | Run date/time (UTC) |
|--------------------|----------------:|-------:|-------:|--------:|-----------:|------:|---------------------|
| `71724104853c8ce6ffcc767fd360c6823117b7a5` *(suite = working tree, not HEAD-only)* | 418 | 417 | 1 | 0 | 0 | 0 | 2026-08-01T21:43:14Z |

**Prior phase baselines (from audit docs; not re-run as the sole evidence for those dates):**

| Source | Passed | Failed |
|--------|-------:|-------:|
| 6B Phase 5 audit claim | 413 | 1 |
| This reconciliation | **417** | **1** |

Delta (+4 passed) is consistent with expanded `DbLeastPrivilegeVerifyTest` (5 methods; previously thinner) and other uncommitted working-tree tests since Phase 5’s documented run — **not** claimed as a new committed SHA.

---

## PART 2 — Rigorous M5 field-by-field workflow audit

Classification labels (Trystan’s binary for these fields):

- **M5 debt — workflow broken/missing/inaccessible** — any of (a)–(e) genuinely absent or non-functional  
- **M5 debt — operational only** — complete enter → validate → persist → retrieve path exists; gap is operational adoption / empty local rows

Local DB probe at evidence time (developer MySQL `hsop_job_command`):  
`jobs` with `actual_labour_hours` = **0**; with `materials_used` = **0**; `review_feedback` = **0**; `pricing_override_logs` = **0**.

---

### 2.1 Region capture (on lead/job)

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | **Could not locate a UI entry point** for structured `region_id` / region picker on Lead or Job screens. Humans enter free-text **address** only (`LeadDetail.jsx` address edit → `PUT /api/leads/{lead}`). Structured `regions` + `properties.region_id` + nullable `leads.property_id` / `jobs.property_id` are **6B Phase 5** artifacts. Region is inferred later by `PropertyAddressParser` inside `LearningRecordAssemblyService::assembleForJob` (CLI `learning:assemble-record`), not at lead/job create/edit time. |
| **b) ROLE** | Address edit: `role:owner,pm` on lead update. Assembly: system/CLI (no human region role). No route grants humans to set `region_id` on lead/job. |
| **c) VALIDATION** | No FormRequest for human `region_id`. Address validation is ordinary lead update rules (not region-scoped). |
| **d) PERSISTENCE** | Free-text `address` persists. `property_id`/`region_id` persist only when assembly creates/links a `properties` row — **not** a human lead/job save path. |
| **e) VISIBILITY** | Address visible on Lead/Job UI. Structured region visible on assembled `learning_records` / via Region model — **no** Lead/Job UI region display found. |
| **f) TEST EVIDENCE** | `LearningRecordAssemblyTest` exercises parser → region on **assembled record**, not a human UI enter→retrieve path on lead/job. **No Feature test covers complete human region capture on lead/job.** |

**Classification: M5 debt — workflow broken/missing/inaccessible**  
(Structured region capture on lead/job is not a completed human workflow; free-text address ≠ region capture.)

---

### 2.2 Planned vs actual labour hours (`jobs.actual_labour_hours`)

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | **Actual:** `frontend/src/pages/JobDetail.jsx` — “Mark Job Complete?” modal (`actual_labour_hours` input) → `POST /api/jobs/{job}/contractor-complete`. **Planned:** **no human UI** for planned labour hours. Planned values come from estimator output stored on `estimate_outcomes.labour_assumptions` (`PricingRangeEstimator` → `EstimateOutcomeRecorder`). |
| **b) ROLE** | Actual: `role:contractor` + must be `job.contractor_id` (`JobController::contractorComplete`). Owner/PM `POST …/mark-complete` does **not** accept labour fields. |
| **c) VALIDATION** | `actual_labour_hours` → `nullable\|numeric\|min:0\|max:9999`. Blank → null (allowed). Invalid type/range → 422. |
| **d) PERSISTENCE** | Yes — lifecycle transition writes `actual_labour_hours` on the job. `ContractorPerformanceRecorder::onContractorComplete` may write `labour_variance` using planned assumptions vs actual. |
| **e) VISIBILITY** | Returned on `GET /api/jobs/{job}` (`toArray()`). Owner/PM: `GET /api/jobs/{job}/learning-snapshot` includes `actual_labour_hours`. **`JobDetail.jsx` does not render `job.actual_labour_hours` after save** (entry-only modal). Planned hours: via estimate/snapshot JSON (`labour_assumptions`), not a dedicated JobDetail field. |
| **f) TEST EVIDENCE** | **Complete API path for actuals:** `LearningDataFoundationTest::test_completion_and_override_endpoints_capture_new_fields` (contractor-complete → assert DB → snapshot). Planned assumptions: `test_estimator_stores_materials_and_labour_assumptions`. **No test asserts JobDetail UI re-display of saved actuals.** |

**Classification: M5 debt — operational only** (for **actual** labour hours enter→persist→API retrieve).  
Caveats (do not upgrade to “complete product UX”): planned side is system-derived only; JobDetail does not show saved actuals; local rows still 0.

---

### 2.3 Planned vs actual materials/cost (`jobs.materials_used`)

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | **Actual:** same JobDetail complete modal — textarea “Materials used (one per line: item, qty, unit)” parsed client-side → `materials_used[]` on `contractor-complete`. **Planned:** estimator `materials_assumptions` on `estimate_outcomes` (no dedicated human materials-plan UI found beyond estimate pipeline). **Cost:** `materials_used` schema is item/qty/unit/note — **not** a unit-cost/total-cost capture field. |
| **b) ROLE** | Contractor only (same as labour). |
| **c) VALIDATION** | `materials_used` nullable array; `*.item` required_with; qty/unit/note optional with limits. Blank omitted → undefined/unchanged. |
| **d) PERSISTENCE** | Yes on job JSON column; variance event via `ContractorPerformanceRecorder`. |
| **e) VISIBILITY** | Job API + learning-snapshot `materials_used_actual`. **No JobDetail post-save display** of materials found. |
| **f) TEST EVIDENCE** | Same `test_completion_and_override_endpoints_capture_new_fields` asserts Primer line persisted + snapshot. |

**Classification: M5 debt — operational only** (actual materials list path).  
Not a materials **cost** capture workflow — say so plainly if Trystan’s matrix meant dollar costs.

---

### 2.4 Duration and completion actuals

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | **Planned completion date:** JobDetail schedule form → `POST /api/jobs/{job}/schedule` (`estimated_completion_date` **required**). **Completion timestamp:** **not human-entered** — set by lifecycle (`customer` accept / owner `mark-complete` / payment paths set `completed_at`). **No dedicated “actual duration hours” column** distinct from `actual_labour_hours`. Performance events (`completion_time`) auto-recorded. |
| **b) ROLE** | Schedule: `role:owner,pm`. Mark-complete (owner/pm) and contractor-complete (contractor) set different statuses; `completed_at` stamped in transitions. |
| **c) VALIDATION** | Schedule: `estimated_completion_date` required, `after_or_equal:scheduled_start_date`. No validation for a human “actual duration” field (none exists). |
| **d) PERSISTENCE** | `estimated_completion_date`, `completed_at`, `customer_accepted_completion_at` on jobs. |
| **e) VISIBILITY** | JobDetail shows schedule fields and “Job completed on {completed_at}” when status completed. |
| **f) TEST EVIDENCE** | Lifecycle/schedule covered in broader job tests (e.g. `JobLifecycleA08A09Test`). **No Feature test found that asserts a dedicated human “duration actuals” enter→retrieve field** beyond schedule + system timestamps / labour hours. |

**Classification: M5 debt — workflow broken/missing/inaccessible**  
for a first-class **duration actuals** capture field. Planned completion date + system `completed_at` exist; that is not the same as capturing measured duration as its own operational learning field (beyond optional labour hours).

---

### 2.5 Margin / outcome capture

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | **Could not locate a UI dedicated to “margin outcome capture” for learning.** Quote **margin** is a **computed** quote field shown in `Quotes.jsx`. Split % editable by owner (`PUT` job split). Financial ledger projects `company_margin`. On customer completion accept, `ContractorPerformanceRecorder::onCompletionAccepted` auto-writes a `profitability` performance event (invoice/quote/estimate figures) — not a human form. |
| **b) ROLE** | Quotes: owner/pm. Ledger/accounting: owner. Profitability event: system on acceptance. |
| **c) VALIDATION** | N/A for a dedicated margin-outcome form. Quote/split have their own rules. |
| **d) PERSISTENCE** | Quote/invoice/ledger rows; optional `contractor_performance_events` profitability payload. No `jobs.margin_outcome` (or similar) column found. |
| **e) VISIBILITY** | Quotes list (margin column); accounting/ledger owner APIs; performance events not given a dedicated SPA viewer located in this audit. |
| **f) TEST EVIDENCE** | Financial/quote tests exist for ops math; **no Feature test found for a complete human “margin outcome capture” learning path.** |

**Classification: M5 debt — workflow broken/missing/inaccessible**  
as **learning margin/outcome capture**. Operational finance margin/quote math exists but is not the same workflow.

---

### 2.6 Feedback capture (`review_feedback`)

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | Customer portal: `frontend/src/pages/CustomerPortal.jsx` → `GET/POST /api/portal/{token}/review`. Backend: `ReviewFeedbackController::portalSubmit` → `ReviewRequestService::submit` → `ReviewFeedback::create`. |
| **b) ROLE** | Submit: unauthenticated portal token (customer). List/follow-up: `role:owner,pm` (`GET /api/reviews`, `PUT /api/reviews/{id}/follow-up`). PM scoped to own `pm_id`. |
| **c) VALIDATION** | `star_rating` required 1–5; `comment` optional; `issue_category` required if rating &lt; 5; photo optional with mime/size limits. |
| **d) PERSISTENCE** | Yes — `review_feedback` table; may open PM follow-up / performance events. |
| **e) VISIBILITY** | Customer sees confirmation in portal. PM: `PMDashboard.jsx` “customer feedback follow-ups” widget. Owner/PM: `GET /api/reviews`. **Could not locate a dedicated `/reviews` SPA page** in `App.jsx` — API + dashboard widget only. |
| **f) TEST EVIDENCE** | `Phase5ReviewsTest` (five-star path, under-five follow-up, source performance / role visibility, Google button). Complete enter→persist→retrieve exercised at API/service level. |

**Classification: M5 debt — operational only**  
(Local rows = 0; UI list is thin but path works.)

---

### 2.7 Override-reason capture (`pricing_override_logs`)

| Lens | Evidence |
|------|----------|
| **a) WHERE entered** | **(1)** Lead ballpark override: `LeadDetail.jsx` → `POST /api/leads/{lead}/price-estimate-override` (`LearningSnapshotController::overrideLeadEstimate`) — creates `PricingOverrideLog` + new `estimate_outcomes` version. **(2)** Pricing rule edit: `PricingRules.jsx` → `PUT /api/pricing-rules/{id}` with optional `override_reason` (`PricingRuleController::update`) — always logs override row; reason may be null. |
| **b) ROLE** | Lead override: `role:owner,pm`. Pricing rules: **`role:owner`** only (middleware group). |
| **c) VALIDATION** | Lead: prices required; `reason` **nullable** `string|max:5000`. Pricing rules: `override_reason` **nullable** `max:5000`. Blank reason still allowed — log may have `reason = null`. |
| **d) PERSISTENCE** | Yes — `pricing_override_logs` with before/after JSON, `override_kind`, actor, lead/job/rule FKs as applicable. |
| **e) VISIBILITY** | Lead UI shows snapshot `manual_override_reason` when present. Learning snapshot `owner_overrides`. **No dedicated override-history SPA** located. |
| **f) TEST EVIDENCE** | `LearningDataFoundationTest::test_completion_and_override_endpoints_capture_new_fields` (lead override + log). `test_pricing_rule_edit_logs_override`. |

**Classification: M5 debt — operational only**  
(Reason is optional; local override rows = 0; path exists.)

---

### 2.8 Summary matrix (Part 2)

| Field | Classification |
|-------|----------------|
| Region capture (on lead/job) | **workflow broken/missing/inaccessible** |
| Planned vs actual labour (`jobs.actual_labour_hours`) | **operational only** (actual path; planned = estimator) |
| Planned vs actual materials (`jobs.materials_used`) | **operational only** (list actuals; not cost) |
| Duration and completion actuals | **workflow broken/missing/inaccessible** (as dedicated duration actuals) |
| Margin/outcome capture | **workflow broken/missing/inaccessible** (as learning capture) |
| Feedback (`review_feedback`) | **operational only** |
| Override reason (`pricing_override_logs`) | **operational only** |

**Prior “all operational” matrix staleness:** Region, duration-as-field, and margin-as-learning-capture were over-classified as operational if judged only by related columns or adjacent finance UI. This audit corrects that.

---

## PART 3 — Current 6B component status (Trystan taxonomy)

**Labels allowed (exactly one each):** Complete and evidenced · Partially complete · In progress · Not started · Blocked by owner input · Blocked by an external provider  

**Commit caveat:** Features below live in the **working tree**; HEAD remains `7172410…`. Cite audit docs + tests; commit hash for 6B rows = **not attributable to a dedicated 6B commit** (note “uncommitted / working tree @ evidence run”).

| Component | Status | Evidence citation |
|-----------|--------|-------------------|
| **Learning AI identity** (`learning_ai` role, abilities, kill switch, access ledger, ping, login block) | **Complete and evidenced** | `docs/audits/6B_phase1_identity_and_eligibility.md`; tests `LearningGatewayIdentityTest` (+ related); suite includes class under `Tests\Feature\LearningGateway\*` |
| **Eligibility recommend/approve split** | **Complete and evidenced** | `docs/audits/6B_phase3_eligibility_authority_rework.md`; `LearningEligibilityTest` — **12 passed** (phase doc); routes recommend vs approve; old direct PATCH removed |
| **`can_finalize_learning_eligibility` default = FALSE** (“treat default answer as none”) | **Complete and evidenced** | Migration `2026_08_01_000020_…` → `->default(false)`; live DB probe: column `Default="0"`, **35/35 users false**, **0 true**; `User::canFinalizeLearningEligibility()` = owner OR flag |
| **Learning Center UI (gateway admin + eligibility backlog)** | **Partially complete** | `docs/audits/6B_phase2_learning_center_ui.md` built SPA; Phase 3 updated `LearningEligibility.jsx` for recommend/approve — **no dedicated frontend Feature/Playwright suite** (phase 2 explicitly skipped inventing FE test framework) |
| **Learning AI write tools** (normalized-record, evidence, recommendation) | **Complete and evidenced** | `docs/audits/6B_phase4_learning_ai_write_tools.md`; `LearningAiWriteToolsTest` — **9 passed** (phase doc) |
| **Source-record immutability guard** | **Complete and evidenced** | Phase 4 doc + `SourceRecordImmutabilityGuard`; tests assert `jobs.actual_labour_hours` unchanged under crafted payloads |
| **`learning_records` canonical assembly** | **Complete and evidenced** (foundation) | `docs/audits/6B_phase5_normalized_learning_record.md`; `LearningRecordAssemblyTest` — **7 passed**; CLI only — **no auto-wire on job completion**, **no browse UI** (doc “Natural next steps”) → still “complete” for scoped phase deliverable; productization remains open |
| **`properties` table** | **Complete and evidenced** (schema + assembly link) | Phase 5 audit + migration `…000041…`; tests assert nullable `property_id` columns + assembly linking. **No human property CRUD UI** |
| **`regions` table (exactly 10 seeded)** | **Complete and evidenced** | Phase 5 audit; migration seeds 10 names; live probe **`regions_count=10`**: Vancouver, Langley, Surrey, Burnaby, Richmond, Coquitlam, New Westminster, North Vancouver, Abbotsford, Chilliwack; `LearningRecordAssemblyTest::test_region_seeder_creates_exactly_ten_documented_regions` |
| **Database two-user least-privilege hardening** | **Partially complete** | `docs/deployment/database_least_privilege_migration.md` + `staging_cutover_checklist.md`; `VerifyDbLeastPrivilegeCommand` + `DbLeastPrivilegeVerifyTest` **5 passed** this run (scratch users). **Production/staging cutover not done**; root still app user in sandbox; Approach B SQL documented but not applied to DO |
| **Embeddings / similarity / retrieval** | **Not started** | Phase 0 + Phase 5 “out of scope / next steps” |
| **Historical import / OCR** | **Not started** | Phase 0 gap list |
| **Learning AI identity Owner confirmation** (dedicated role vs reuse) | **Blocked by owner input** | Phase 1 “Assumption flags” — proceeded with dedicated role pending Owner confirmation |
| **Placeholder rate retirement / real market rates** | **Blocked by owner input** | Phase 0 owner input #8 |
| **Weather / environmental_context population** | **Blocked by an external provider** | Phase 0 — weather API deferred; column reserved unused |

### Default finalize flag — explicit verification

```
users_total=35
can_finalize_true=0
can_finalize_false=35
column_default="0" type=tinyint(1) null=NO
```

Consistent with Trystan’s “default answer = none” — no user is a finalize delegate unless Owner sets the flag (Owners finalize via role without the flag).

---

## Appendix A — Commands run for this document

```text
git log --oneline -20
git rev-parse HEAD
git log -1 --format="%H%n%ci%n%s"
git status -sb
cd backend && php artisan test
  → Tests: 1 failed, 417 passed (2512 assertions); Duration: 140.37s
php artisan test --list-tests   → 418 listed tests
rg markTestSkipped|@skip|markTestIncomplete under backend/tests
php artisan test --filter=PublicIntakePhase1Test::test_unknown_domain_returns_404
php artisan test --filter=DbLeastPrivilegeVerifyTest
# DB probe: users can_finalize counts; Region::count(); jobs actuals / review_feedback / pricing_override_logs counts
```

## Appendix B — What this document is not

- Not a production cutover attestation  
- Not a claim that HEAD SHA contains 6B commits  
- Not a claim that local 0-row tables mean production is empty  
- Not permission to reclassify “operational only” fields as “done for learning quality” without Verified eligibility + real populated jobs  

---

*End of evidence reconciliation and M5 workflow audit.*
