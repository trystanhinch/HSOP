# Milestone 6A Phase 5 — Evaluation Harness Foundation

**Date:** 2026-07-31  
**Scope:** AI-to-AI evaluation run/finding data model, `review:evidence-write` tools, owner visibility in Review Center, local smoke scorer.  
**Out of scope:** Real OpenAI adversarial evaluation (needs staging), operational AI behavior changes, Review Center redesign.

---

## What was built

### 1. Evaluation data model (append-only)
- **`ai_evaluation_runs`** — provider-neutral metadata per Trystan: Provider, Model, Model version, Prompt version, Evaluation version, Benchmark set version, Run type, Initiated by, Timestamps, Cost, Status, Trace/Run ID.
- **`ai_evaluation_findings`** — polymorphic subject (`ai_conversation_log` | `ai_action_log`), Core Evaluation Dimension, score/max_score, critique, **statement_kind** (`observed_fact` | `inference` | `recommendation` for EVAL-11), evidence_reference.
- Eloquent `booted()` forbids update/delete on both models (same pattern as gateway access logs). No `updated_at` columns.

### 2. First review-gateway write tools (`review:evidence-write`)
- `POST /api/review-gateway/tools/evaluation-run`
- `POST /api/review-gateway/tools/evaluation-finding`
- Ability separation enforced: `review:read` alone → 403 with `required_ability=review:evidence-write`.
- Findings require an existing run owned by the same `actor_user_id`; missing → 404; foreign actor → 403.
- **Narrow write exception:** route-registry test now allows **only** these two POST paths under `/api/review-gateway/*`; all other tools remain GET-only.

### 3. Owner visibility (Review Center extended)
- `GET /api/admin/review-gateway/evaluation-runs` (owner)
- `GET /api/admin/review-gateway/evaluation-runs/{id}/findings` (owner)
- Review Center adds a read-only “Evaluation runs” table + click-to-expand findings (no redesign).

### 4. Smoke validation (no live LLM)
- `php artisan review-ai:smoke-evaluation [--limit=5] [--dry-run]`
- `PlaceholderEvaluationScorer` scores existing `ai_conversation_logs` on **scope_completeness**, **factual_grounding**, **tool_correctness** using deterministic heuristics (content length, provider/model presence, tool_calls/results). Cost recorded as `0`.

---

## Files touched

| Area | Paths |
|------|--------|
| Migrations | `database/migrations/2026_07_31_180001_create_ai_evaluation_runs_table.php`, `…180002_create_ai_evaluation_findings_table.php` |
| Models | `app/Models/AiEvaluationRun.php`, `AiEvaluationFinding.php` |
| Tools | `app/Services/ReviewGateway/EvaluationRunTool.php`, `EvaluationFindingTool.php`, `PlaceholderEvaluationScorer.php` |
| Controllers | `ReviewGatewayController.php`, `AdminReviewGatewayController.php` |
| Routes / config | `routes/api.php`, `config/review_gateway.php` (`evaluation` + tool versions) |
| Logger | `ReviewGatewayAccessLogger.php` (infer evaluation tools) |
| Command | `app/Console/Commands/SmokeEvaluationHarnessCommand.php` |
| Tests | `tests/Feature/ReviewGateway/EvaluationHarnessFoundationTest.php`; Foundation route-registry test updated |
| Frontend | `frontend/src/pages/ReviewCenter.jsx` (evaluation section only) |

---

## Screen / API inventory

| Surface | Method | Auth |
|---------|--------|------|
| Create evaluation run | `POST /api/review-gateway/tools/evaluation-run` | `external_review_ai` + `review:evidence-write` |
| Append finding | `POST /api/review-gateway/tools/evaluation-finding` | same |
| List runs | `GET /api/admin/review-gateway/evaluation-runs` | `role:owner` |
| List findings | `GET /api/admin/review-gateway/evaluation-runs/{id}/findings` | `role:owner` |
| Smoke CLI | `review-ai:smoke-evaluation` | artisan (local) |

---

## Test results

**New:** `EvaluationHarnessFoundationTest` — 7 passed (ability gate, write path, ownership, append-only update/delete, owner/non-owner admin, smoke command).

**Updated:** `ReviewGatewayFoundationTest::test_3_review_gateway_write_routes_are_narrow_evidence_exception` — allows only the two evaluation POSTs.

**Full suite (post Phase 5):** **362 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`). Delta vs 6B Phase 2 baseline (355): **+7** from `EvaluationHarnessFoundationTest`.

---

## Assumption flags

1. **Core Evaluation Dimensions (initial enum):**  
   `scope_completeness`, `pricing_timing`, `factual_grounding`, `tool_correctness`, `authorization`, `safety_escalation`, `privacy_security`, `consistency` — taken from the 6A package’s Core Evaluation Dimensions named in the Phase 5 brief (no separate package file in-repo). Stored in `config/review_gateway.evaluation.dimensions`.

2. **Smoke dimensions subset:** only three dimensions above are exercised by `PlaceholderEvaluationScorer` — enough to prove schema + write path without pretending to be a full rubric.

3. **Why placeholder scoring (not stub OpenAI):** adversarial / live provider evaluation is staging-gated; a deterministic local scorer validates persistence and metadata without API keys, cost, or flaky network. Default `provider` remains `openai` in metadata to keep the architecture provider-neutral and aligned with Trystan’s chosen initial provider.

4. **Append-only runs:** status/cost/completed_at are set **at create time** only — there is no PATCH to complete a `running` run this phase. Smoke creates runs as `completed` with `total_cost=0`. Documented so a later “complete run” event can be a deliberate exception if needed.

5. **Identity reuse:** writes use existing `external_review_ai` + `review:evidence-write` — no new role.

6. **Ownership:** finding writes keyed to `actor_user_id` of the run (same principal user), not token-id equality — two tokens of the same actor may append to each other’s runs.

7. **Cascade FK:** DB FK on findings → runs uses `cascadeOnDelete` for schema hygiene; Eloquent still forbids deletes on both tables.

---

*End of Phase 5 audit.*
