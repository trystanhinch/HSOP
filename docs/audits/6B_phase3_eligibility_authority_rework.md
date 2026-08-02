# Milestone 6B Phase 3 — Learning Eligibility Authority Rework

**Date:** 2026-08-01  
**Scope:** Replace equal Owner/PM direct status transitions with Trystan’s recommend vs finalize model.  
**Out of scope:** Pricing engine, review-gateway, 6A monitoring, staging config, Owner UI to manage the finalize flag beyond a minimal API (schema + endpoint exist).

---

## What changed vs Phase 1/2

| Before (Phase 1/2) | After (Phase 3) |
|--------------------|-----------------|
| `PATCH …/learning-eligibility/{id}` let **owner and pm** set `learning_eligibility_status` directly | Route **removed**. Status changes only via `approve` |
| Single AuditLog action `learning_eligibility_changed` | `learning_eligibility_recommended` + `learning_eligibility_finalized` |
| PM “Change status” was final | PM **Recommend** only (does not change status) |
| No recommendation fields | `learning_recommended_*` + `learning_approved_*` on estimate_outcomes and jobs |
| Role-hardcoded authority | `users.can_finalize_learning_eligibility` flag (default false); Owners finalize via role; named PM delegates via flag |

**Confirmed:** the old direct `PATCH /api/admin/learning-eligibility/{id}` and `PATCH …/jobs/{job}` bypass routes **no longer exist** (404/405).

---

## Authority model (implemented)

1. **Recommend** (`recommend` / `recommendJob`) — `role:owner,pm`  
   Writes recommended status, reason (required), missing actuals; **does not** change `learning_eligibility_status`. Supersedes prior recommendation in place; history in AuditLog.

2. **Approve / finalize** (`approve` / `approveJob`) — Owner **or** `can_finalize_learning_eligibility=true`  
   Only path that sets `learning_eligibility_status`. Reason required. Override of PM recommendation allowed and logged (`override: true`).

3. **Production learning set** — `EstimateOutcome::productionLearningSet()` = `learning_eligibility_status = 'verified'` only. Recommendations alone never qualify. Provisional remains a distinct status and is flagged `is_provisional` / not `in_production_learning_set`.

4. **Delegation hook** — `can_finalize_learning_eligibility` on `users` (default false). Owner-only `PATCH /api/users/{user}/can-finalize-learning` `{ enabled }`. Owners do not need the flag (`User::canFinalizeLearningEligibility()` = owner OR flag).

---

## API

| Method | Path | Who |
|--------|------|-----|
| GET | `/api/admin/learning-eligibility` | owner, pm — includes recommendation fields + `recommendation_state` + `viewer.can_finalize` |
| PATCH | `/api/admin/learning-eligibility/{id}/recommend` | owner, pm |
| PATCH | `/api/admin/learning-eligibility/{id}/approve` | owner or finalize flag (plain PM → **403**) |
| PATCH | `/api/admin/learning-eligibility/jobs/{job}/recommend` | owner, pm |
| PATCH | `/api/admin/learning-eligibility/jobs/{job}/approve` | owner or finalize flag |
| PATCH | `/api/users/{user}/can-finalize-learning` | owner only |

---

## Frontend

`LearningEligibility.jsx` extended (not rebuilt):
- PM: Recommend modal (explicit “not final”) + missing actuals
- Owner / finalize: Approve recommendation, Finalize/override, Pending recommendations filter
- Table distinguishes: no recommendation / pending approval / accepted / overridden; provisional labelled; production-set badge only when verified

---

## Files touched

| Area | Paths |
|------|--------|
| Migration | `2026_08_01_000020_phase3_learning_eligibility_authority.php` |
| Service | `LearningEligibilityService.php` (recommend / approve split) |
| HTTP | `LearningEligibilityController.php`, `routes/api.php`, `UserController::setCanFinalizeLearning` |
| Models | `User`, `EstimateOutcome` (+ `scopeProductionLearningSet`), `Job` |
| Frontend | `LearningEligibility.jsx` |
| Tests | `tests/Feature/LearningEligibility/LearningEligibilityTest.php` (rewritten) |

---

## Test results

**LearningEligibilityTest — 12 passed**  
PM recommend / 403 on approve · Owner recommend+finalize · PM with flag can finalize · reason required on both · override logged · recommend-only excluded from production set · old PATCH gone · supersede + list fields · contractor forbidden · columns exist.

**Full suite:** **397 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`).

| Baseline (Phase 10) | This phase |
|---------------------|------------|
| 392 passed | **397** (+5 net; eligibility suite expanded from 7→12) |

---

## Explicit confirmations

1. **Old direct-PATCH bypass route no longer exists** — covered by `test_old_direct_patch_route_removed`.
2. **Recommendation alone ≠ Verified in production set** — `productionLearningSet()` + test.
3. **Delegation schema is addable without rework** — boolean flag + owner setter; no hardcoded “only owner forever” beyond the Owner always-on path.

---

*End of 6B Phase 3 audit.*
