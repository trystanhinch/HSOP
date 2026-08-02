# Milestone 6B Phase 2 — Learning Center Owner UI

**Date:** 2026-07-31  
**Scope:** Owner/PM frontend for Learning Gateway admin + eligibility review backlog. Consumes Phase 1 APIs only.  
**Out of scope:** Backend changes, Review Center edits, pricing, issue-token UI, bulk eligibility actions, auto-reconcile of job vs estimate status.

---

## What was built

### 1. Learning Gateway admin (`/learning-gateway`)
Mirrors `ReviewCenter.jsx` structure:
- Identity banner for dedicated `learning_ai`
- KPI cards: call volume (24h/7d/30d), denied, active tokens, kill-switch toggle
- Nearing-expiration warning (when API returns it)
- Token table + revoke (artisan issue still only)
- Filterable access-log table with pagination

**Access:** `RoleGuard` + nav → **owner only** (matches backend `/api/admin/learning-gateway/*`).

### 2. Learning Eligibility backlog (`/learning-eligibility`)
- Status filter tabs: Pending review (default), Provisional, Verified, Excluded, All
- Table: estimate id/range, lead/job links, service, estimate status, job status, placeholder badge, reviewed meta, Change status action
- **Placeholder badge:** amber warning with `AlertTriangle` — not auto-excluded
- **Status drift:** when job-level ≠ estimate-level eligibility, shows “Status drift” warning (no auto-reconcile)
- **Change status modal:** status select + **required** reason textarea; Save disabled until reason non-empty; backend still validates

**Access:** owner + pm (matches Phase 1 backend policy).

### 3. Navigation
- `navConfig.js`: Learning Gateway (`Brain` icon, owner), Learning Eligibility (`ClipboardList` icon, owner+pm) — placed after Review Center (`Shield`) so icons stay distinct
- `App.jsx` routes + `AppLayout.jsx` title map

---

## Screen / component inventory

| Screen | File | Route | Roles |
|--------|------|-------|-------|
| Learning Gateway | `frontend/src/pages/LearningGateway.jsx` | `/learning-gateway` | owner |
| Learning Eligibility | `frontend/src/pages/LearningEligibility.jsx` | `/learning-eligibility` | owner, pm |

**Touched**
- `frontend/src/nav/navConfig.js`
- `frontend/src/App.jsx`
- `frontend/src/components/AppLayout.jsx`
- `docs/audits/6B_phase2_learning_center_ui.md` (this file)

**Backend:** none.

---

## Frontend tests

Existing Playwright specs (`frontend/tests/self-verify.spec.js`, `mobile-actions.spec.js`) are live-app smoke flows (demo logins, specific job IDs) — **no** Review Center / admin-page precedent that mocks auth cleanly.  
**Skipped** inventing a new frontend test framework. Role gating relies on existing `RoleGuard` + nav `roles` filters (same pattern as Review Center).

---

## Backend regression

`php artisan test` after this phase (no backend code changed): expected still **355 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`). Confirmed in suite run for this deliverable.

---

## Assumption flags

1. **Icons:** Learning Gateway uses Lucide `Brain`; Eligibility uses `ClipboardList`; Review Center keeps `Shield` — intentional visual separation in the sidebar.  
2. **Kill-switch confirm copy:** Explicitly states Review AI and ops AI kill switches are unaffected.  
3. **Eligibility modal:** Custom in-page modal (not SweetAlert input) so reason-required UX is clear and Save stays disabled without a reason.  
4. **Owner+PM eligibility:** UI allows both roles; does not hardcode owner-only. Still pending Trystan’s Phase 1 policy confirmation.  
5. **Job-level PATCH:** UI only transitions **estimate** outcomes via `PATCH /admin/learning-eligibility/{id}`. Job status is displayed for drift visibility; no separate job-status edit control this phase (keeps single-row estimate transitions simple).  
6. **Issue token:** Still artisan-only (`learning-ai:issue-token`), same as Review Center.

---

*End of Phase 2 audit.*
