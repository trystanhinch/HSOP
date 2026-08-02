# Milestone 6A Phase 3 — Review Center (Owner UI)

**Date:** 2026-07-30  
**Scope:** Owner-only admin APIs + React Review Center screen for visibility, kill-switch control, and token revoke.  
**Out of scope:** No changes to `/api/review-gateway/*` tools/middleware; no “issue token” UI; staging / AI-to-AI / monitoring unchanged.

---

## What was built

### Backend (`role:owner` under `/api/admin/review-gateway/*`)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/admin/review-gateway/summary` | Call/denied counts (24h/7d/30d), active token count, kill-switch state, most recently used token |
| GET | `/api/admin/review-gateway/access-logs` | Paginated logs; filters: outcome, tool, token_name, date_from, date_to |
| GET | `/api/admin/review-gateway/tokens` | Issued tokens whose abilities overlap `review:*` — **id, name, abilities, created_at, last_used_at only** (no secret) |
| POST | `/api/admin/review-gateway/tokens/{id}/revoke` | Deletes `personal_access_tokens` row; writes **AuditLog** (`review_gateway_token_revoked`) |
| PATCH | `/api/admin/review-gateway/kill-switch` | Body `{ "enabled": true\|false }` → `review_gateway_kill_switch`; **AuditLog** (`review_gateway_kill_switch_changed`) |

These routes are **not** gated by `review:*` Sanctum abilities — human Owner login only.

### Frontend
- Page: `frontend/src/pages/ReviewCenter.jsx`
- Route: `/review-center` (Owner `RoleGuard`)
- Nav: **Review Center** (Shield icon) in `navConfig.js` for `owner`
- App shell title map updated in `AppLayout.jsx`

**UI sections**
1. **Dashboard cards** — call volume, denied, active tokens (+ last used), kill-switch status + toggle  
2. **Kill-switch** — SweetAlert2 `confirmDanger` before toggle (same weight as other destructive confirms; AI Settings uses a checkbox+banner pattern — Review Center uses explicit confirm because the control is a one-click engage/clear)  
3. **Token table** — name, abilities, created, last used, Revoke (confirm dialog)  
4. **Access log table** — filters + pagination matching AI Activity Log conventions  

### Confirmed gap (pre-Phase 3)
There was **no** Owner UI or HTTP API to list/revoke review tokens — only `php artisan review-ai:issue-token` and direct DB. Phase 3 adds **list + revoke** only; **issue remains artisan-only** (flagged as a natural future addition, not built).

---

## New / touched files

**New**
- `backend/app/Http/Controllers/Api/AdminReviewGatewayController.php`
- `backend/tests/Feature/ReviewGateway/ReviewCenterAdminTest.php`
- `frontend/src/pages/ReviewCenter.jsx`
- `docs/audits/6A_phase3_review_center_ui.md` (this file)

**Modified**
- `backend/routes/api.php` — admin review-gateway routes
- `frontend/src/App.jsx` — route
- `frontend/src/nav/navConfig.js` — nav item
- `frontend/src/components/AppLayout.jsx` — page title

---

## Assumption flags

### 1. Kill-switch UX vs AI Settings checkbox
AI Settings saves kill switch via form checkbox + red banner. Review Center uses a **card + confirmDanger dialog** because this page’s primary control is a live toggle (no separate Save). Copy warns that tokens are not deleted when the switch is engaged.

### 2. Kill-switch API field name
`PATCH` body uses `{ "enabled": boolean }` where **`enabled: true` = kill switch ON (gateway blocked)** — matches `ai_kill_switch` / Phase 1 semantics, not “gateway enabled”.

### 3. “Active tokens”
Count = all `personal_access_tokens` whose stored abilities JSON contains any configured `review:*` ability (not filtered by last_used_at).

### 4. No issue-token UI
Intentionally omitted per task. UI notes artisan command. Future: Owner “Issue token” that wraps `review-ai:issue-token` and shows the plaintext once.

### 5. Access-log filters
Tool filter is free-text `LIKE` match (not a dropdown of registered tools) — simplest reuse of AI Activity Log filter UX without a new filters endpoint.

### 6. Revoke confirmation copy
Assumed wording emphasizes permanent invalidation and artisan re-issue — not Owner-supplied copy.

---

## Component / screen inventory (no screenshots captured)

| Region | Components / structure |
|--------|-------------------------|
| Header | `PageHeader` + subtitle text |
| KPI row | 4 cards (volume, denied, tokens, kill switch) |
| Kill warning | Conditional red banner when switch ON |
| Tokens | Table + Revoke buttons + artisan hint |
| Logs | Filter bar + table + prev/next pagination |
| Dialogs | `confirmDanger` / `showSuccess` / `showError` from `utils/swal` |

---

## Test results

**Phase 3:** `ReviewCenterAdminTest` — **6 passed**.

| # | Case |
|---|------|
| 1 | Owner can hit summary / access-logs / tokens |
| 2 | pm, contractor, customer, content_editor → 403 on all admin review-gateway routes |
| 3 | Revoke deletes token; subsequent `/api/review-gateway/*` → 401/403; AuditLog written |
| 4 | Admin kill-switch ON blocks gateway; OFF restores (same effect as Phase 1) |
| 5 | Tokens list never includes secret / `token` field |
| 6 | Access logs filter by outcome |

**Full suite:** **326 passed, 1 failed** (`PublicIntakePhase1Test > unknown domain returns 404` — pre-existing).  
Phase 2 baseline was **320 / 1**; this phase adds **+6** → **326 / 1**.  
All ReviewGateway suites combined: **21 passed** (Phase 1 + 2 + 3).
