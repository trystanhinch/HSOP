# Milestone 6B Phase 1 — Learning AI Identity & Eligibility State Machine

**Date:** 2026-07-31  
**Scope:** Dedicated `learning_ai` service identity (parallel to 6A Phase 4 `external_review_ai`, not shared) + human-driven learning eligibility status on `estimate_outcomes` / `jobs`.  
**Out of scope (unchanged):** pricing rules, `PricingRangeEstimator`, `PricingService`, customer-facing math, embeddings population, historical import, Review AI gateway, `ai_super_admin`, Learning Center frontend UI.

---

## What was built

### Task 1 — Dedicated Learning AI identity
- **Role:** `learning_ai` added to `users.role` ENUM (migration). Existing roles untouched.
- **Config:** `config/learning_ai.php` — actor email, abilities `learning:read`, `learning:eligibility-write`, `learning:evidence-write`, TTL 90d, kill-switch key, eligibility status list.
- **Middleware:** `EnsureLearningAiAbility` (`learning.ai`) — role **and** explicit ability (rejects Sanctum `*`); `LogLearningGatewayAccess` (`learning.gateway.log`).
- **Login:** Interactive login blocked for `learning_ai` (same 403 as ops/review AI actors).
- **Issuance:** `php artisan learning-ai:issue-token {name} [--ttl=] [--email=]` on dedicated principal (`learning-ai@serviceop.system` by default).
- **Ledger:** Append-only `learning_gateway_access_logs` (Eloquent update/delete forbidden).
- **Kill switch:** `learning_gateway_kill_switch` — independent of `ai_kill_switch` and `review_gateway_kill_switch`.
- **Gateway route (auth probe only):** `GET /api/learning-gateway/ping` — confirms Learning AI auth; no real learning data tools yet.
- **Owner admin APIs (backend only, no SPA this phase):**
  - `GET /api/admin/learning-gateway/summary`
  - `GET /api/admin/learning-gateway/access-logs`
  - `GET /api/admin/learning-gateway/tokens`
  - `POST /api/admin/learning-gateway/tokens/{id}/revoke`
  - `PATCH /api/admin/learning-gateway/kill-switch`  
  Gated by `role:owner` only.

### Task 2 — Learning eligibility state machine
- Columns on **`estimate_outcomes`** and **`jobs`:**  
  `learning_eligibility_status` (default + backfill `pending_review`),  
  `learning_eligibility_reason`,  
  `learning_eligibility_reviewed_by`,  
  `learning_eligibility_reviewed_at`.
- Statuses: `pending_review` | `provisional` | `verified` | `excluded`.
- **Service:** `LearningEligibilityService` — transitions require non-empty reason; writes `AuditLog` (`learning_eligibility_changed`) with prior/new status. **No auto-verified / auto-excluded.**
- Placeholder estimates surfaced via `flags.is_placeholder_estimate` — **flagged, not auto-excluded**.
- **Human APIs** (`role:owner,pm` — not under learning-gateway):
  - `GET /api/admin/learning-eligibility?status=`
  - `PATCH /api/admin/learning-eligibility/{estimateOutcomeId}` `{ status, reason }`
  - `PATCH /api/admin/learning-eligibility/jobs/{job}` (job-level independent status)

### Isolation confirmed
- `learning_ai` cannot call `/api/review-gateway/*`.
- `external_review_ai` / `ai_super_admin` cannot call `/api/learning-gateway/*`.
- Kill switches do not cross-contaminate (tested).

---

## New / touched files

**New**
- `backend/database/migrations/2026_07_31_120001_add_learning_ai_role.php`
- `backend/database/migrations/2026_07_31_120002_create_learning_gateway_access_logs_table.php`
- `backend/database/migrations/2026_07_31_120003_add_learning_eligibility_columns.php`
- `backend/config/learning_ai.php`
- `backend/app/Services/LearningGateway/LearningAiPrincipal.php`
- `backend/app/Services/LearningGateway/LearningGatewayAccessLogger.php`
- `backend/app/Services/Learning/LearningEligibilityService.php`
- `backend/app/Models/LearningGatewayAccessLog.php`
- `backend/app/Http/Middleware/EnsureLearningAiAbility.php`
- `backend/app/Http/Middleware/LogLearningGatewayAccess.php`
- `backend/app/Http/Controllers/Api/LearningGatewayController.php`
- `backend/app/Http/Controllers/Api/AdminLearningGatewayController.php`
- `backend/app/Http/Controllers/Api/LearningEligibilityController.php`
- `backend/app/Console/Commands/IssueLearningAiTokenCommand.php`
- `backend/tests/Feature/LearningGateway/LearningGatewayIdentityTest.php`
- `backend/tests/Feature/LearningEligibility/LearningEligibilityTest.php`
- `docs/audits/6B_phase1_identity_and_eligibility.md` (this file)

**Modified**
- `backend/bootstrap/app.php` — middleware aliases
- `backend/routes/api.php` — learning-gateway + admin routes
- `backend/app/Http/Controllers/Api/AuthController.php` — login block
- `backend/app/Models/User.php`, `EstimateOutcome.php`, `Job.php`
- `backend/app/Http/Controllers/Api/UserController.php`, `AdminUserController.php`
- `backend/database/seeders/Milestone4Seeder.php` — learning kill-switch default + principal
- `backend/.env.example`

---

## Test results

| Suite | Result |
|-------|--------|
| `LearningGateway*` + `LearningEligibility*` | **17 passed** |
| Full `php artisan test` | **355 passed, 1 failed** |
| Pre-existing failure | `PublicIntakePhase1Test > unknown domain returns 404` (unchanged from Phase 4 baseline 338→355) |

---

## Next phase (explicitly deferred)

- Owner SPA for Learning Gateway admin + eligibility review backlog UI  
- Real learning data read/write tools under `/api/learning-gateway/*` (beyond ping)  
- Embedding generation / population of `embedding_vector`  
- Historical import  

---

## Assumption flags (pending Owner / Trystan)

### 1. Dedicated `learning_ai` role — **ASSUMPTION**
Phase 0 audit said Owner must decide Learning AI identity. This phase proceeded with a **new dedicated role** (same successful pattern as 6A Phase 4 for Review AI), explicitly **not** reusing `external_review_ai` or `ai_super_admin`. Flagged pending confirmation from the audit email.

### 2. Eligibility transitions: `owner` + `pm` — **ASSUMPTION**
Phase 0 open question on who may mark Verified/Excluded. Implemented **owner and pm** to match existing PM authorization patterns. Not owner-only; not Learning AI writes. Pending Trystan’s answer — do not treat as final policy.

### 3. Placeholder estimates not auto-excluded
Surfaced in API `flags` only. Auto-exclude remains an Owner policy decision.

### 4. Write abilities reserved, unused for tools this phase
`learning:eligibility-write` and `learning:evidence-write` are defined on tokens for future Learning AI scoped writes. Phase 1 eligibility mutations are **human** Owner/PM routes only — Learning AI does not write eligibility yet.

### 5. Job-level vs estimate-level eligibility
Both columns exist. Human list/filter UI targets estimate outcomes first; job PATCH is available for independent job classification.

---

*End of Phase 1 audit.*
