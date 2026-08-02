# Milestone 6A Phase 6 — Ledger Tamper Hardening (SEC-09)

**Date:** 2026-07-31  
**Scope:** Database-level append-only enforcement on the four audit/evidence ledger tables.  
**Out of scope:** Other tables, application route/controller logic, staging, privilege redesign.

---

## What was built

### Enforcement approach (Context verification)
**Single shared DB user confirmed.**  
`config/database.php` exposes one MySQL credential pair (`DB_USERNAME` / `DB_PASSWORD`) for the `mysql` connection. Runtime check: default connection `mysql`, username `root`, database `hsop_job_command`. There is **no** migration-only connection or elevated migration user.

**Therefore triggers were chosen** (not privilege revocation). App + migrations use the same user; revoking UPDATE/DELETE on these tables from that user would also break legitimate migration DDL tooling unless a second user existed.

### Trigger layer (independent of Eloquent)
For each of:
- `review_gateway_access_logs`
- `learning_gateway_access_logs`
- `ai_evaluation_runs`
- `ai_evaluation_findings`

Migration creates:
- `BEFORE UPDATE` → `SIGNAL SQLSTATE '45000'` with `… is append-only: updates are not permitted`
- `BEFORE DELETE` → `SIGNAL SQLSTATE '45000'` with `… is append-only: deletes are not permitted`

No column carve-outs. INSERT remains allowed. Eloquent `booted()` LogicException guards are **unchanged** (first layer); triggers are the second layer.

### Tooling
| Command | Purpose |
|---------|---------|
| `php artisan ledger:verify-triggers` | Read-only health check (all 4 tables × UPDATE+DELETE) |
| `php artisan ledger:reapply-triggers --force` | Deliberate re-apply after schema work |
| `php artisan ledger:reapply-triggers --drop-only --force` | Deliberate DROP window before ALTER |

`--force` is mandatory so the escape hatch is not silent.

### Escape hatch (documented in migration header)
Future ALTER / row repair on these tables must:
1. DROP triggers (`ledger:reapply-triggers --drop-only --force`)
2. Make the change
3. Re-apply (`ledger:reapply-triggers --force`)

---

## Files touched

| Path | Role |
|------|------|
| `database/migrations/2026_07_31_200001_add_append_only_ledger_triggers.php` | Create/drop triggers + escape-hatch docs |
| `app/Services/Ledger/LedgerAppendOnlyTriggers.php` | Shared apply/drop/verify |
| `app/Console/Commands/VerifyLedgerTriggersCommand.php` | `ledger:verify-triggers` |
| `app/Console/Commands/ReapplyLedgerTriggersCommand.php` | `ledger:reapply-triggers` |
| `tests/Feature/Ledger/LedgerTamperHardeningTest.php` | Raw-query tamper + truncate/drop behavior |
| `docs/audits/6A_phase6_ledger_tamper_hardening.md` | This file |

**No** route/controller/model logic changes (Eloquent guards left as-is).

---

## Test database refresh vs triggers

### What the suite actually does today
- Feature tests almost universally use **`DatabaseTransactions`** (not `RefreshDatabase`).
- `RefreshDatabase` appears only commented-out in `ExampleTest`.
- phpunit.xml defaults `DB_CONNECTION=sqlite` / `:memory:`, but Review/Learning/Ledger Feature tests force **`mysql` + `hsop_job_command`** in `createApplication()`.
- Transaction **ROLLBACK** undoes inserts without issuing DELETE — **DELETE triggers do not fire**. Confirmed compatible; no trigger weakening required.

### Observed DDL / truncate behavior (explicit tests)
| Operation | DELETE trigger fires? | Result |
|-----------|----------------------|--------|
| `DB::table()->delete()` | Yes | Blocked (QueryException / SQLSTATE 45000) |
| `DB::table()->update()` | Yes (UPDATE trigger) | Blocked |
| `DB::table()->insert()` | N/A | Allowed |
| `TRUNCATE TABLE` | **No** (MySQL) | Succeeds — documented gap; not protected by this layer |
| `DROP TABLE` | **No** | Succeeds — so `migrate:fresh`-style DROP+recreate is safe and re-runs trigger migration |

**Conclusion:** Test reset via transactions is safe. A DROP-based refresh would also be safe. Triggers were **not** weakened for tests. TRUNCATE remains a privileged DDL bypass of DELETE triggers — noted for future MON/privilege work if a migration-only user is ever introduced.

---

## Test results

**New:** `LedgerTamperHardeningTest` — 5 passed:
1. Raw `DB::table` UPDATE/DELETE blocked on all 4 ledgers  
2. Raw INSERT still works  
3. `ledger:verify-triggers` healthy  
4. TRUNCATE bypass documented on sandbox table  
5. DROP TABLE bypass confirmed (migrate:fresh-safe)

**Full suite (post Phase 6):** **367 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`). Delta vs Phase 5 baseline (362): **+5**.

---

## Assumption flags

1. Shared `root` (or single `DB_USERNAME`) remains the deployment model — if a migration-only user is added later, prefer GRANT/REVOKE on these tables **in addition to** triggers.  
2. Trigger migration no-ops on non-MySQL drivers (SIGNAL is MySQL-specific); Feature suite for these ledgers already forces MySQL.  
3. FK `cascadeOnDelete` on `ai_evaluation_findings` is moot while parent DELETE is trigger-blocked; DROP TABLE still cascades at schema level when tables are dropped together.  
4. Eloquent-layer tests that expect `LogicException` still pass — they never reach the DB.

---

*End of Phase 6 audit.*
