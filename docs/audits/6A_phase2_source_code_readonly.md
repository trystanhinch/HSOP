# Milestone 6A Phase 2 — Read-Only Source Code Access

**Date:** 2026-07-30  
**Scope:** Allowlisted, ability-gated source-file + source-search tools under `/api/review-gateway/*`, gated by **`review:code-read`** (not `review:read`).  
**Out of scope (unchanged):** staging, AI-to-AI eval, monitoring, Phase 1 data tools (`lead-journey`, `search`, `ai-conversation-log`).

---

## What was built

### Ability separation
- Data tools remain `review.ai` → requires explicit `review:read`.
- Source tools use `review.ai:review:code-read` → requires explicit `review:code-read`.
- A token with only one of these abilities cannot call the other surface (tested both directions).

### Allowlist (`config/review_gateway_code_scope.php`)
Readable prefixes (relative to monorepo root):
- `backend/app/`
- `backend/config/`
- `backend/database/migrations/`
- `backend/routes/`
- `backend/tests/`
- `docs/`

**Hard excludes (always win):** `.env*`, path fragments matching `secret` / `credential`, `storage/`, `vendor/`, `node_modules/`, `.git/`, `database/seeders/`.

Path resolution: reject `..` segments, `realpath()`, then verify the real path sits under an allowlisted absolute directory (blocks traversal/symlink escape).

### Routes (GET only)
| Method | Path | Ability |
|--------|------|---------|
| GET | `/api/review-gateway/tools/source-file?path=` | `review:code-read` |
| GET | `/api/review-gateway/tools/source-search?query=&path_prefix=` | `review:code-read` |

Disallowed paths return **403** (not 404), with `denial_reason` recorded on `review_gateway_access_logs` (outcome `denied`). Missing *allowlisted* files may return 404.

### Response provenance
Both tools return `tool`, `tool_version` (`1.0.0`), resolved relative `path` (source-file), and **`content_sha256`** (file body for source-file; matches JSON for source-search).

### Search implementation
PHP recursive scan over allowlisted directories only — **no shell / ripgrep**. Query length capped; match/file caps from config.

---

## New / touched files

**New**
- `backend/config/review_gateway_code_scope.php`
- `backend/app/Services/ReviewGateway/SourceCodePathGuard.php`
- `backend/app/Services/ReviewGateway/SourceFileTool.php`
- `backend/app/Services/ReviewGateway/SourceSearchTool.php`
- `backend/tests/Feature/ReviewGateway/ReviewGatewaySourceCodeTest.php`
- `docs/audits/6A_phase2_source_code_readonly.md` (this file)

**Modified**
- `backend/config/review_gateway.php` — tool versions for source tools
- `backend/routes/api.php` — nested ability groups for data vs code
- `backend/app/Http/Controllers/Api/ReviewGatewayController.php` — source endpoints
- `backend/app/Http/Middleware/EnsureReviewAiAbility.php` — ability attr + already-logged flag
- `backend/app/Http/Middleware/LogReviewGatewayAccess.php` — skip double-log; 403 → denied; matches count
- `backend/app/Services/ReviewGateway/ReviewGatewayAccessLogger.php` — source tool inference + ability from request
- `backend/tests/Feature/ReviewGateway/ReviewGatewayFoundationTest.php` — `forgetGuards()` between tokens (test isolation)

---

## Assumption flags (not silent decisions)

### 1. Allowlist paths vs task shorthand
Task examples (`app/`, `config/`, …) map to **`backend/app/`**, **`backend/config/`**, etc., because Laravel lives under `backend/` in this monorepo; `docs/` is at repo root. Override root with `REVIEW_GATEWAY_REPO_ROOT` if needed.

### 2. Demo-password seeders — **FLAGGED, EXCLUDED**
These files contain `Hash::make('password')` (and similar) for demo accounts and are **not** on the allowlist; additionally hard-excluded via `database/seeders/`:
- `backend/database/seeders/DemoSeeder.php` — owner/pm/contractor/customer demo password `password`
- `backend/database/seeders/Milestone4Seeder.php` — also creates demo users with `Hash::make('password')` (plus random password for `ai_super_admin`)

**Owner decision needed:** whether a future allowlist should ever include seeders (not recommended) or whether demo passwords should be rotated out of seeders entirely. This phase does **not** serve either file.

### 3. Search engine
Chose in-process PHP search instead of ripgrep/shell to avoid command-injection risk. Owner may later approve a hardened `proc_open` argv-array ripgrep for performance.

### 4. `.env.example`
Hard-excluded with the `.env*` family even though it is often considered “safe.” Prefer missing public docs over accidental secret template leakage; Owner can carve an exception later.

### 5. Symlink tests
Traversal via `../` is covered. Creating OS symlinks in CI/Windows is environment-dependent; protection relies on `realpath()` + allowlist prefix checks (same path used for symlink escape).

---

## Test results

**Phase 2:** `ReviewGatewaySourceCodeTest` — **7 passed**.  
**Phase 1 regression:** `ReviewGatewayFoundationTest` — **8 passed**.  
**Combined ReviewGateway:** **15 passed**.

| # | Case |
|---|------|
| 1 | `review:code-read` reads allowlisted file + `content_sha256` matches body |
| 2 | `.env*`, seeders, vendor hard-excluded → 403 |
| 3 | `review:read` only → source 403; `review:code-read` only → data search 403 |
| 4 | Path traversal (`../`, nested `..`) → 403 |
| 5 | Denied code-read logged on `review_gateway_access_logs` |
| 6 | `source-search` matches only allowlisted prefixes |
| 7 | No write verbs on source-file / source-search (registry + 405) |

**Full suite:** **320 passed, 1 failed** (`PublicIntakePhase1Test > unknown domain returns 404` — pre-existing).  
Phase 1 baseline was **313 passed / 1 failed**; this phase adds **+7** source tests → **320 / 1**.
