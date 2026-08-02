# Milestone 6A Phase 1 — Review Gateway Foundation

**Date:** 2026-07-30  
**Scope:** External Review AI identity, scoped Sanctum abilities, GET-only `/api/review-gateway/*` tools (3), sensitive-data scrubbing, append-only access ledger, kill switch.  
**Out of scope (unchanged):** staging infra, AI-to-AI evaluation, continuous monitoring, source-code read tools, Command Center / human routes.

---

## What was built

### Identity & auth
- Sanctum abilities: `review:read`, `review:code-read`, `review:evidence-write` (`config/review_gateway.php`).
- Artisan: `php artisan review-ai:issue-token {name}` → mints token on **`ai_super_admin`**, prints plaintext once.
- Middleware `EnsureReviewAiAbility` (`review.ai`): requires **explicit** ability membership (does **not** accept Sanctum’s default `*` from human `createToken('auth_token')`).
- Middleware `LogReviewGatewayAccess` (`review.gateway.log`): logs success/error after tool execution.
- Kill switch setting: `review_gateway_kill_switch` (same `Setting::getBool` pattern as `ai_kill_switch`, **independent** key so ops AI and review gateway can be paused separately).

### Routes (GET only)
| Method | Path |
|--------|------|
| GET | `/api/review-gateway/tools/lead-journey/{leadId}` |
| GET | `/api/review-gateway/tools/search` |
| GET | `/api/review-gateway/tools/ai-conversation-log/{conversationId}` |

Gated by: `auth:sanctum` + `active.user` + `review.ai` + `review.gateway.log`.

### Tools (`app/Services/ReviewGateway/`)
| Tool | Class | `tool_version` |
|------|-------|----------------|
| lead_journey | `LeadJourneyTool` | 1.0.0 |
| search | `ReviewSearchTool` | 1.0.0 |
| ai_conversation_log | `AiConversationLogTool` | 1.0.0 |

Each response includes `tool` + `tool_version` and is passed through `SensitiveDataGuard`.

### Ledger
- Migration/table: `review_gateway_access_logs`
- Model: `ReviewGatewayAccessLog` — Eloquent `updating`/`deleting` throw `LogicException` (append-only)
- Denied ability / kill-switch attempts logged from `EnsureReviewAiAbility`

---

## New / touched files

**New**
- `backend/config/review_gateway.php`
- `backend/database/migrations/2026_07_30_120001_create_review_gateway_access_logs_table.php`
- `backend/app/Models/ReviewGatewayAccessLog.php`
- `backend/app/Http/Middleware/EnsureReviewAiAbility.php`
- `backend/app/Http/Middleware/LogReviewGatewayAccess.php`
- `backend/app/Http/Controllers/Api/ReviewGatewayController.php`
- `backend/app/Console/Commands/IssueReviewAiTokenCommand.php`
- `backend/app/Services/ReviewGateway/SensitiveDataGuard.php`
- `backend/app/Services/ReviewGateway/ReviewGatewayAccessLogger.php`
- `backend/app/Services/ReviewGateway/LeadJourneyTool.php`
- `backend/app/Services/ReviewGateway/ReviewSearchTool.php`
- `backend/app/Services/ReviewGateway/AiConversationLogTool.php`
- `backend/tests/Feature/ReviewGateway/ReviewGatewayFoundationTest.php`
- `docs/audits/6A_phase1_review_gateway_foundation.md` (this file)

**Modified**
- `backend/bootstrap/app.php` — middleware aliases
- `backend/routes/api.php` — review-gateway group
- `backend/database/seeders/Milestone4Seeder.php` — default kill-switch setting

---

## Assumption flags (not silent decisions)

### 1. `ai_super_admin` vs new role — **ASSUMPTION / OPEN**
Phase 0 Owner Input Needed: reuse `ai_super_admin` or invent a dedicated role.  
**This phase attaches tokens to `ai_super_admin` only**, with an explicit TODO on `IssueReviewAiTokenCommand` and this doc. No new role was created. Owner must confirm before production reviewer issuance policy is finalized.

### 2. Kill switch key
Used **`review_gateway_kill_switch`** (true = blocked), not the operational `ai_kill_switch`, so External Review can be disabled without pausing lead-intake AI. Semantics match `ai_kill_switch` (engaged = deny).

### 3. Explicit abilities vs `tokenCan()`
Human login tokens use Sanctum default abilities `['*']`, which would make `tokenCan('review:read')` true. Middleware therefore requires the ability string to appear **literally** on the token — `*` is insufficient. Flagged as intentional hardening.

### 4. `{conversationId}` meaning
Interpreted as `ai_conversation_logs.id`; tool returns that turn plus siblings sharing `intake_session_id` (fallback: same `trace_id`).

### 5. Search implementation
Implemented a review-only query in `ReviewSearchTool` (leads + jobs). Did **not** call `JobController::search`.

---

## Test results

**Feature suite:** `ReviewGatewayFoundationTest` — **8 passed** (44 assertions).

| # | Case |
|---|------|
| 1 | Valid review token → all 3 tools OK + `tool_version` |
| 2 | Auth without `review:*` → 403 |
| 3 | Route registry GET-only + POST/PUT/PATCH/DELETE → 405 |
| 4 | Denied calls recorded in `review_gateway_access_logs` |
| 5 | Sensitive denylist scrub / no denylist keys in responses |
| 6 | Kill switch on → 403 even with valid review token; off restores access |
| 7 | Eloquent update throws |
| 8 | Eloquent delete throws |

**Full suite (post-change):** **313 passed, 1 failed** (`PublicIntakePhase1Test > unknown domain returns 404` — pre-existing). New ReviewGateway tests: **+8**.

---

## How to issue a token (ops)

```bash
cd backend
php artisan review-ai:issue-token "external-reviewer-atlas-1"
# Copy the printed bearer token once; store in a secret manager.
```

Disable gateway without revoking tokens:

```php
Setting::setBool('review_gateway_kill_switch', true);
```
