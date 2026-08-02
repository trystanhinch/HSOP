# Milestone 6B Phase 4 — Learning AI Write Tools (Authority Boundaries)

**Date:** 2026-08-01  
**Scope:** Replace Phase 1 ping-only gateway with structural write tools under `/api/learning-gateway/*`, enforcing Trystan’s MAY / MAY NOT list in code (not docs alone).  
**Reuses:** Phase 1 ledger + kill switch; Phase 3 `LearningEligibilityService::recommend*` (unchanged API surface for humans).  
**Out of scope:** embeddings, historical import, similar-job retrieval, market-source registry, pricing engine, review-gateway, 6A monitoring/staging.

---

## What was built

### Task 1 — Structural enforcement against finalize
- Sanctum abilities still only: `learning:read`, `learning:eligibility-write`, `learning:evidence-write` — **none map to approve**.
- Routes: `eligibility-write` → `POST …/tools/recommendation` only (calls `recommendEstimateOutcome` / `recommendJob`). **No approve route** under learning-gateway.
- Defense-in-depth inside `LearningEligibilityService::approveEstimateOutcome` / `approveJob`: if actor role is `learning_ai`, throw immediately — even if `can_finalize_learning_eligibility` were wrongly set.

### Task 2 — Write tools
| Route | Ability | Behavior |
|-------|---------|----------|
| `POST /api/learning-gateway/tools/normalized-record` | `learning:evidence-write` | Creates append-only `learning_normalized_records` draft; status **pending_review** or **provisional** only (`verified`/`excluded` in payload coerced to `pending_review`) |
| `POST /api/learning-gateway/tools/evidence` | `learning:evidence-write` | Appends `learning_evidence_entries` (never updates prior rows) |
| `POST /api/learning-gateway/tools/recommendation` | `learning:eligibility-write` | Same `LearningEligibilityService::recommend*` path as PMs; `recommended_by` = learning_ai actor |

### Task 3 — Source-record immutability
- `SourceRecordImmutabilityGuard` silently strips source-of-truth keys / nested `job|quote|customer|payment|invoice` blobs from tool payloads.
- Tools never call `Job::update` / quote / customer writers — only learning tables + recommendation columns.

### Task 4 — Pricing isolation
- Explicit regression test: learning_ai → `/api/pricing-rules*` → 403 (owner middleware).

### Task 5 — Ledger + kill switch
- Existing `learning.gateway.log` + `EnsureLearningAiAbility` cover new routes; `inferTool` recognizes the three tools.
- Kill switch blocks all three (tested).

---

## Files touched

| Area | Paths |
|------|--------|
| Migration | `2026_08_01_000030_create_learning_normalized_records_and_evidence.php` |
| Models | `LearningNormalizedRecord`, `LearningEvidenceEntry` (append-only Eloquent guards) |
| Services | `LearningAiWriteTools`, `SourceRecordImmutabilityGuard`; `LearningEligibilityService` (+ `assertNotLearningAi`) |
| HTTP | `LearningGatewayController` (3 tools); `routes/api.php` ability-scoped groups; `LearningGatewayAccessLogger::inferTool` |
| Tests | `tests/Feature/LearningGateway/LearningAiWriteToolsTest.php` |

---

## Test results

**LearningAiWriteToolsTest — 9 passed**  
create/evidence/recommend never Verified · service-layer approve block (flag ignored) · job.actual_labour_hours unchanged after crafted payload · pricing_rules 403 · access logs · kill switch · no approve route · ability gating.

**Related:** LearningEligibilityTest (12) + LearningGatewayIdentityTest (10) still green.

**Full suite:** **406 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`).

| Baseline (6B Phase 3) | This phase |
|-----------------------|------------|
| 397 passed | **406** (+9) |

---

## Trystan MAY / MAY NOT → enforcement map

### MAY

| Item | Enforcement |
|------|-------------|
| Create draft / Provisional normalized learning records | `LearningAiWriteTools::createNormalizedRecord` + `learning_normalized_records`; status ∈ {pending_review, provisional} |
| Populate extracted/derived fields with provenance | `extracted_fields` + `provenance` on create |
| Attach confidence, source refs, warnings, missing-data flags | create payload + `POST …/evidence` append entries |
| Create Pending Review items | default / coerced `pending_review` status on drafts |
| Write estimate recommendations, evidence, evaluation-style outputs | recommendation tool + evidence table (comparison/quality findings can attach as evidence JSON this phase) |
| Recommend Verified or Excluded via **same** recommend() path as PM | `submitRecommendation` → `recommendEstimateOutcome` / `recommendJob`; test asserts status stays pending until human finalize |

### MAY NOT

| Item | Enforcement |
|------|-------------|
| Mark Verified/Excluded alone (never call approve) | No gateway approve route; `assertNotLearningAi` in `approve*`; tests force service misuse with finalize flag → RuntimeException |
| Overwrite original customer/job/quote/payment/completed-work evidence | `SourceRecordImmutabilityGuard` silent strip; tools never write those columns; test asserts `jobs.actual_labour_hours` unchanged |
| Invent missing values | Strip + no write path to invent into source tables; missing data only as flags/notes on learning tables |
| Change a source record silently | Same immutability guard + no Job/Quote updates in write tools |
| Publish material/labour rate | No pricing_rules routes on learning gateway; pricing isolation test |
| Change production pricing rules | Same |
| Promote learning version/model/prompt/retrieval/policy | Not implemented (no promote routes); abilities do not include promote |
| Activate customer-facing pricing | No pricing activation routes under learning.ai |
| Approve its own recommendation | Structural: cannot call approve; service guard even if mis-routed |

---

## Explicit confirmations

1. **`learning:eligibility-write` only reaches recommend** — wired solely to `/tools/recommendation`.
2. **Service-layer approve guard is independent of routing** — proven by direct `approveEstimateOutcome` / `approveJob` calls with learning_ai + finalize flag.
3. **Kill switch + access ledger** reuse Phase 1 infrastructure; new tools logged by name.

---

*End of 6B Phase 4 audit.*
