# 6A + 6B Local Verification Report

**Purpose:** Accurate, defensible picture of what is **proven to work right now** in this sandbox before any staging/production deployment decision.  
**Mode:** Verification only — no application code, migrations, or configuration were modified for this pass.  
**Run window (UTC):** 2026-08-01T21:51:50Z – 2026-08-01T21:56:40Z  

---

## 1. Git state

### Branch

```
main
```

### `git log --oneline -10`

```
7172410 Add CT-05 through CT-12 remaining Contractor portal findings: Stripe onboarding UI, assignment states, agenda schedule, availability, payout eligibility, empty states, onboarding checklist (audit remediation batch 23 — FINAL, completes 63-item audit)
20d681d Add PM-05 through PM-15 remaining PM portal findings: Stripe status clarity, dashboard labels, empty states, customer quarantine verification, schedule agenda, invoice context (audit remediation batch 22)
f577a8a Add A-36 Brand Content agency-safe SEO workflow, permissions, and technical SEO controls (audit remediation batch 21)
bf857f9 Add A-15/A-24/A-30/A-31 business-hours workflow timing, availability safeguards, admin messaging, and unified calendar (audit remediation batch 20)
364c347 Add A-34/A-35 Jobs ops filters and Leads confidence/duplicate management (audit remediation batch 19)
c227d2b Add A-26 report chart drill-down and A-32 quote lifecycle separation, including escalation status-overwrite bug fix (audit remediation batch 18)
fc137d3 Add A-20/A-25 GST/markup/split calculator and Company Sources parser health visibility (audit remediation batch 17)
37e2429 Add A-17/A-18/A-27 AI governance: mode enforcement, activity traceability, and Command Center evidence (audit remediation batch 16)
09c8053 Add A-16/A-19/A-21 message template safety, notification channel health, and delivery log usability (audit remediation batch 15)
ae4838f Add A-13/A-14/A-23 company legal identity, user/role management, and developer diagnostics lockdown (audit remediation batch 14)
```

### Commit hash (HEAD)

`71724104853c8ce6ffcc767fd360c6823117b7a5`

### Working tree — **not clean**

```
On branch main
Your branch is ahead of 'origin/main' by 24 commits.

Changes not staged for commit: (31 modified tracked files under backend/ + frontend/)
Untracked files: extensive — includes all Milestone 6A/6B implementation
  (Review/Learning gateway, monitoring, staging, learning_records, least-privilege,
   docs/audits/, docs/deployment/, Feature tests, .do/, tmp-*, etc.)
```

**Implication:** Suite + smoke checks exercised the **working tree**, not a single committed SHA that contains 6A/6B. HEAD remains the July 30 CT-05–CT-12 audit remediation commit. Do not treat `7172410` as “6A/6B shipped.”

---

## 2. Full test suite

### Command

```
cd backend
php artisan test
```

Raw tee log: `tmp-6ab-verify-suite.txt` (514 lines). Duration **156.21s**. Ended `2026-08-01T21:54:33Z`.

### Exact counts

| Metric | Count |
|--------|------:|
| Collected (417 + 1) | **418** |
| Passed | **417** |
| Failed | **1** |
| Skipped | **0** |
| Incomplete | **0** |
| Risky | **0** |
| Assertions | **2512** |

### Exact PHPUnit footer

```
Tests:    1 failed, 417 passed (2512 assertions)
Duration: 156.21s
```

### Pre-existing failure (unchanged)

**`Tests\Feature\PublicIntakePhase1Test > unknown domain returns 404`**

```
FAILED  Tests\Feature\PublicIntakePhase1Test > unknown domain returns 404
Expected response status code [404] but received 200.
Failed asserting that 200 is identical to 404.

at tests\Feature\PublicIntakePhase1Test.php:66
```

Unrelated to 6A/6B (public intake brand-domain resolution).

### Skipped tests this run

**None.** PHPUnit reported zero skipped.

**Conditional skip sites that exist in code but did not fire:**

| Test method | Skip reason (when triggered) |
|-------------|------------------------------|
| `DbLeastPrivilegeVerifyTest::test_verify_reports_fail_against_current_root_grants` | `MySQL only` if driver ≠ mysql |
| `…::test_runtime_identity_fails_on_root_including_ddl` | same |
| `…::test_migrate_identity_fails_on_root` | same |
| `…::test_scratch_two_user_identities_when_admin_can_create_users` | `MySQL only` **or** `Cannot CREATE USER in this sandbox: …` |
| Same class `test_invalid_identity_rejected` | no skip |

This run: `DbLeastPrivilegeVerifyTest` → **5 passed** (scratch CREATE USER succeeded).

**Not a skip:** method display name `7b pm and company payouts skipped for manual review` is a **passing** behavioural test.

---

## 3. Phase-by-phase functional smoke checks

HTTP checks used Laravel’s HTTP kernel in-process (same middleware stack as a live request). First harness pass showed Auth user leakage across sequential kernel handles without `Auth::forgetGuards()` — that is a **harness artifact**, not a product defect. Checks 2b/2c were re-run with guards cleared; those results are authoritative.

---

### 2a) Review Gateway — **PASS**

**Commands / actions:**

```
php artisan review-ai:issue-token "verify-test" --ttl=1
GET /api/review-gateway/tools/search?q=drywall&limit=5
  Authorization: Bearer <issued>
DELETE personal_access_tokens id=1488
```

**Real output (excerpt):**

```
Review AI token minted. …
token_id=1488
actor_user_id=29696
actor_role=external_review_ai
abilities=review:read,review:code-read,review:evidence-write

GET /api/review-gateway/tools/search status=200
body_snippet={"tool":"search","tool_version":"1.0.0",…,"data":[{"entity":"lead",…},{"entity":"job",…}
access_logs_before=30 after=31
latest_log={"id":1188,"tool":"search","http_status":200,"path":"/api/review-gateway/tools/search","actor_user_id":29696}
cleanup: deleted personal_access_tokens id=1488 exists_after=no
```

**Verdict:** Token issue → authenticated search 200 → row in `review_gateway_access_logs` → token cleaned.

---

### 2b) Learning Gateway normalized-record — **PASS** (after auth-reset rerun)

**First attempt (harness bug):** Bearer resolved as prior `external_review_ai` → **403** `learning_role_required`, access log actor_user_id=29696. Not treated as product failure.

**Authoritative rerun:**

```
php artisan learning-ai:issue-token "verify-test-2" --ttl=1
POST /api/learning-gateway/tools/normalized-record
  { subject_type: job, subject_id: 1, learning_eligibility_status: provisional, … }
```

**Real output:**

```
token_id=1493 actor_user_id=35942 actor_role=learning_ai
abilities=learning:read,learning:eligibility-write,learning:evidence-write

status=201
record_status=provisional id=37
body includes: "note":"Draft only — status is pending_review or provisional. Verified/Excluded require human finalize."
log={"id":282,"tool":"normalized-record","http_status":201,"actor_user_id":35942,…}
logs_delta=27->28
```

**Verdict:** Creates **Provisional** (not Verified); logged in `learning_gateway_access_logs`; token cleaned. Draft row **id=37** left in DB (append-only hygiene — not deleted).

---

### 2c) Eligibility recommend / approve split — **PASS** (after auth-reset rerun)

**Actors:** Owner `admin@hsop.com` (id=1, can finalize via role); PM `pm@hsop.com` (id=2, `can_finalize_learning_eligibility=0`).  
**Subject:** `estimate_outcomes.id=91` (was `pending_review`).

| Step | HTTP | Result |
|------|-----:|--------|
| PM `PATCH …/recommend` `{status:verified, reason:…}` | **200** | `learning_eligibility_status` stays `pending_review`; `learning_recommended_status=verified` |
| PM `PATCH …/approve` | **403** | `Forbidden: finalizing eligibility requires Owner or can_finalize_learning_eligibility.` Status unchanged |
| Owner `PATCH …/approve` | **200** | `learning_eligibility_status=verified` |
| Owner restore to `pending_review` | **200** | Restored after probe |

**Verdict:** Recommend does not finalize; PM approve rejected; Owner finalize works.

---

### 2d) Ledger tamper protection — **PASS**

**Command (real SQL via Eloquent/query builder):**

```php
DB::table('review_gateway_access_logs')->where('id', 1188)->delete();
```

**Real error:**

```
BLOCKED_AS_EXPECTED: Illuminate\Database\QueryException
SQLSTATE[45000]: <<Unknown error>>: 1644
review_gateway_access_logs is append-only: deletes are not permitted
(SQL: delete from `review_gateway_access_logs` where `id` = 1188)
```

**Verdict:** MySQL trigger blocks delete — not a mocked PHPUnit assertion.

---

### 2e) Database least-privilege — **FAIL (expected locally)**

**Command:**

```
php artisan db:verify-least-privilege --identity=current
```

**Result:** **FAIL** — local `DB_USERNAME=root` with `GRANT ALL PRIVILEGES ON *.* … WITH GRANT OPTION`.

```
Identity profile: current
Inspecting: root@127.0.0.1 / DB=hsop_job_command (CURRENT_USER)
LEAST-PRIVILEGE CHECK: FAIL — identity [current] is out of bounds.
```

**Verdict:** Accurate for this sandbox. Two-user cutover not applied locally (documented separately). Not a regression.

---

### 2f) Monitoring / alerts / correlation — **PASS with notes**

**Environment constraints discovered:**

- `QUEUE_CONNECTION=sync` in `.env`
- Laravel `queue.connections.database.table` defaults to `jobs`, which is the **domain jobs** table (incompatible schema)

**What was done (honest):**

1. Runtime-only (not config-file) switch: create temp table `laravel_queue_jobs_verify_tmp`, point queue table at it, `queue.default=database`.
2. `dispatch(closure that throws)->onConnection('database')->onQueue('verify')`
3. `php artisan queue:work database --queue=verify --once --tries=1`

**Real output:**

```
queue:work … Closure … RUNNING … FAIL
failed_jobs before=0 after=1
exception_snippet=RuntimeException: 6AB local verification intentional queue failure …
alerts before=0 after=2
latest_alert={"severity":"high","message":"Queue job failed permanently: Closure …",
  "context":{"source":"queue.job_failed","connection":"database","queue":"verify",
  "uuid":"1bfa9186-d74a-4fa2-87ea-d60ded4b849a",…}}
```

**Observation:** One permanent failure produced **two** `alerts` rows (ids 560 and 561) with the same uuid. One was cleaned during harness; **alert id=560 remains** as leftover evidence. Possible double listener registration — reported as found, not fixed.

**Correlation ID (HTTP middleware) — verified on successful request:**

```
GET /api/me
X-Correlation-Id: verify-corr-me-dfd79514-da5d-4f3e-ba2b-a7fb71bd9ef7
status=200
resp_corr=verify-corr-me-dfd79514-da5d-4f3e-ba2b-a7fb71bd9ef7
Log::sharedContext correlation_id="verify-corr-me-dfd79514-da5d-4f3e-ba2b-a7fb71bd9ef7"
```

**Note:** Queue `JobFailed` alert context carries job `uuid`, not the HTTP `X-Correlation-Id` (different execution path). Outbound Twilio/Resend/Stripe correlation propagation was **not** re-proven in this smoke (covered by Feature tests only).

Temp queue table dropped; probe `failed_jobs` row removed.

---

### 2g) Learning record assembly — **PASS**

**Command:**

```
php artisan learning:assemble-record 1
```

**Real output:**

```
Assembled learning_record #43 group=c05e75e0-f296-434f-b395-61e313a6e707 version=1
eligibility=pending_review (source job:1)
Missing sources: lead_photos, estimate_outcomes, review_feedback

learning_records before=0 after=1
record_id=43 version=1 property_id=31 region_id=1
provenance_is_array=yes
provenance_keys_sample=["job_id","job_status","service_category","scope_of_work","address_raw",…]
provenance_snippet={"job_id":{"source_table":"jobs","source_id":1,…,"provenance_type":"imported"},…}
```

**Verdict:** Row created with populated provenance JSON. Record **#43** left in DB.

---

## 4. Smoke check scoreboard

| Check | Result |
|-------|--------|
| 2a Review Gateway | **PASS** |
| 2b Learning Gateway write tool | **PASS** |
| 2c Eligibility recommend/approve | **PASS** |
| 2d Ledger append-only trigger | **PASS** |
| 2e Least-privilege current identity | **FAIL (expected — root)** |
| 2f Failed job → alert + correlation middleware | **PASS** (double alert noted; queue needed temp table) |
| 2g `learning:assemble-record` | **PASS** |

---

## 5. Honest gap list — cannot prove from this sandbox

| Item | Why not proven here |
|------|---------------------|
| Real Stripe webhook delivery from Stripe → App Platform | No live Stripe signing / public endpoint in sandbox |
| Real Twilio SMS / Resend email delivery | Providers not exercised live; Feature tests use mocks/fakes |
| DigitalOcean App Platform deploy / PRE_DEPLOY migrate job | No DO deploy in this pass |
| Two-user DB cutover on managed MySQL (`serviceop_app` / `serviceop_migrate`) | Local still `root`; staging checklist not executed |
| DO managed MySQL backup / restore | Staging-only |
| Production Gmail OAuth / inbox fetch against live mailbox | Not run |
| Real Slack alert webhook delivery | Slack URL empty locally; only `alerts` table path proven |
| Default `QUEUE_CONNECTION=sync` + domain `jobs` table collision | Database queue worker path needs dedicated queue table / config change before production |
| Outbound correlation on live Twilio/Resend/Stripe/OpenAI calls | Code + Feature tests exist; not live-provider proven |
| Frontend SPA smoke (Review Center / Learning Gateway UI) | Backend/API only this pass |
| Auto-assembly of `learning_records` on job completion | CLI only by design |
| Committed release artifact containing 6A/6B | Working tree unclean; HEAD lacks 6A/6B |

---

## 6. Regressions vs prior known state

| Item | Assessment |
|------|------------|
| `PublicIntakePhase1Test` 404→200 | **Unchanged** pre-existing failure — not new |
| Suite 417 passed / 1 failed | Matches prior reconciliation baseline (+least-privilege tests already counted) |
| New product regressions found this pass | **None confirmed** |
| New observations (not prior “suite fails”) | (1) JobFailed produced **2 alerts** for 1 failure; (2) local queue table name collides with domain `jobs`; (3) in-process multi-request harness must clear Auth guards |

---

## 7. Leftover artifacts from this verification (not cleaned)

| Artifact | Notes |
|----------|-------|
| `learning_normalized_records.id=37` | provisional draft from 2b |
| `learning_records.id=43` | assembly from job 1 |
| `alerts.id=560` | leftover from double JobFailed alert |
| `review_gateway_access_logs` / `learning_gateway_access_logs` | new rows from smoke (append-only) |
| Temp harness scripts | `tmp-6ab-smoke-harness.php`, `tmp-6ab-smoke-rerun.php`, `tmp-6ab-corr.php`, logs |

---

## 8. Bottom line

**Proven locally right now (working tree):** Review AI search + access log; Learning AI provisional normalized-record + access log; PM recommend / PM approve 403 / Owner approve; ledger delete blocked by trigger; failed queue job → `failed_jobs` + `alerts` (with double-alert caveat); HTTP correlation ID middleware; `learning:assemble-record` with provenance; suite **417/418** with one known intake failure.

**Not proven / not ready to claim for production:** committed 6A/6B SHA, least-privilege identities on real DB, App Platform deploy separation, live payment/SMS/email/webhook paths, backup/restore, default database-queue config without table collision.

---

*End of 6AB local verification report.*
