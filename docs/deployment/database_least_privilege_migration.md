# Database least-privilege migration (two-user model — required)

**Date:** 2026-08-01 (updated)  
**Purpose:** Replace `DB_USERNAME=root` with **two** dedicated MySQL identities per Trystan’s written decision — closing the Phase 6 gap where `TRUNCATE` bypasses DELETE triggers (MySQL grants `TRUNCATE` via `DROP`), and ensuring the live app cannot expand its own privileges.  
**Scope:** SQL artifacts, deploy credential-swapping guidance, ops documentation, and `php artisan db:verify-least-privilege`. Does **not** change production credentials in this sandbox.

---

## Decision (supersedes prior Approach A)

| Prior doc (Aug 1 draft) | Trystan decision (now binding) |
|-------------------------|--------------------------------|
| Approach A — one combined user — **recommended for first cutover** | **Superseded / not applicable** for production cutover |
| Approach B — two users — optional follow-up | **Required now** |

**Root/admin must not remain in the application environment at all** (no long-lived App Platform `DB_USERNAME=root`). Break-glass admin access stays offline in the team secret store / DO console — never as the runtime app credential.

---

## Current state (this sandbox)

| Item | Value |
|------|--------|
| Configured user | `root` |
| Host | `127.0.0.1` / `localhost` |
| Database | `hsop_job_command` |
| Grants | `GRANT ALL PRIVILEGES ON *.* … WITH GRANT OPTION` |

**Production/staging DB name may differ.** Replace `` `{DB_NAME}` `` and `{APP_HOST}` below before running.

---

## 1. Privilege tiers (exact)

### What migrations need (inspected across `backend/database/migrations/**`)

| Operation | Privilege |
|-----------|-----------|
| `Schema::create` | `CREATE` |
| `Schema::table` / ENUM `ALTER` | `ALTER` |
| Indexes / unique | `INDEX` |
| Foreign keys | `REFERENCES` (+ `ALTER`) |
| Phase 6 `CREATE TRIGGER` / `DROP TRIGGER` | `TRIGGER` |
| Backfill DML | `SELECT`, `INSERT`, `UPDATE`, `DELETE` |
| `Schema::dropIfExists` / `migrate:fresh` | **`DROP`** — **not granted** to either identity |

### Runtime — `serviceop_app`

```
SELECT, INSERT, UPDATE, DELETE,
CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW
ON `{DB_NAME}`.*
```

**Explicitly NO:** `CREATE`, `ALTER`, `INDEX`, `REFERENCES`, `TRIGGER`, `DROP`, `GRANT OPTION`, `CREATE USER`, `SUPER`, `FILE`, `PROCESS`, …

Cannot expand its own permissions (no `GRANT OPTION`).

### Migration — `serviceop_migrate`

Everything runtime has, **plus:**

```
CREATE, ALTER, INDEX, REFERENCES, TRIGGER
ON `{DB_NAME}`.*
```

**Still NO:** `DROP` (and therefore **TRUNCATE**), `GRANT OPTION`, `CREATE USER`, `SUPER`, `FILE`, `PROCESS`, …

> Phase 6: `TRIGGER` is required for ledger append-only migrations / `ledger:reapply-triggers`. No `DROP` preserves TRUNCATE-blocking for **both** identities.

> `migrate:fresh` / `staging:reset` need `DROP` — use a break-glass admin session for those destructive ops only, never the app or migrate identities.

---

## 2. Primary SQL — create both identities

Run as an admin/`root` session. Replace:

- `{APP_HOST}` — typically `%` on DigitalOcean managed MySQL; `localhost` for local TCP/socket
- `{DB_NAME}` — staging/production schema name
- `{RUNTIME_PASSWORD}` / `{MIGRATE_PASSWORD}` — distinct strong secrets (never commit)

```sql
-- ============================================================
-- serviceop_app — RUNTIME (ordinary application operation)
-- ============================================================
CREATE USER IF NOT EXISTS 'serviceop_app'@'{APP_HOST}' IDENTIFIED BY '{RUNTIME_PASSWORD}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'serviceop_app'@'{APP_HOST}';
GRANT
  SELECT, INSERT, UPDATE, DELETE,
  CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW
ON `{DB_NAME}`.*
TO 'serviceop_app'@'{APP_HOST}';

-- ============================================================
-- serviceop_migrate — MIGRATION / DEPLOY ONLY (not runtime)
-- ============================================================
CREATE USER IF NOT EXISTS 'serviceop_migrate'@'{APP_HOST}' IDENTIFIED BY '{MIGRATE_PASSWORD}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'serviceop_migrate'@'{APP_HOST}';
GRANT
  SELECT, INSERT, UPDATE, DELETE,
  CREATE, ALTER, INDEX, REFERENCES, TRIGGER,
  CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, SHOW VIEW
ON `{DB_NAME}`.*
TO 'serviceop_migrate'@'{APP_HOST}';

FLUSH PRIVILEGES;

SHOW GRANTS FOR 'serviceop_app'@'{APP_HOST}';
SHOW GRANTS FOR 'serviceop_migrate'@'{APP_HOST}';
```

### Verify after create

```bash
# Runtime profile (must PASS; must FAIL if CREATE/ALTER present)
php artisan db:verify-least-privilege --identity=runtime \
  --username=serviceop_app --password='…' --host=… --database='{DB_NAME}'

# Migrate profile (must PASS; must have CREATE/ALTER/INDEX/REFERENCES/TRIGGER; no DROP)
php artisan db:verify-least-privilege --identity=migrate \
  --username=serviceop_migrate --password='…' --host=… --database='{DB_NAME}'
```

### Env vars (runtime components — web, queue, scheduler)

| Variable | Value |
|----------|--------|
| `DB_USERNAME` | `serviceop_app` |
| `DB_PASSWORD` | runtime password only |
| `DB_DATABASE` / `DB_HOST` / `DB_PORT` | unchanged |

**Do not** put `serviceop_migrate` password on long-lived web/worker/scheduler components.

---

## 3. Deploy credential swapping (migrate-only use)

### Requirement

`serviceop_migrate` is used **only** during controlled deploys for `php artisan migrate --force`, then discarded for that process. It is **not** the ordinary runtime application credential.

### DigitalOcean App Platform constraint (honest)

App Platform does **not** give a perfect “inject secret for one subprocess then erase it from the same container” primitive while the web dyno keeps running. Shared component env is the default. Therefore **true separation** means **separate components / jobs**, not two passwords on the same web service.

### Most secure practical approach on DO

1. **Runtime components** (web, `queue:work`, `schedule:work`):  
   `DB_USERNAME=serviceop_app` / runtime password only.

2. **One-shot PRE_DEPLOY (or CI) job** dedicated to migrations:  
   - Same image / `source_dir: /backend`  
   - `run_command: php artisan migrate --force && php artisan db:verify-least-privilege --identity=migrate`  
   - Env: `DB_USERNAME=serviceop_migrate` + migrate password (encrypted SECRET)  
   - **Does not** run continuously; exits after migrate  
   - Web/worker never receive that SECRET

3. After PRE_DEPLOY succeeds, App Platform starts/restarts runtime components still on `serviceop_app`.

Example sketch (illustrative — wire real secrets in DO UI / `doctl`, do not commit passwords):

```yaml
jobs:
  - name: db-migrate
    kind: PRE_DEPLOY
    environment_slug: php
    source_dir: /backend
    run_command: >
      php artisan migrate --force
      && php artisan db:verify-least-privilege --identity=migrate
    envs:
      - key: DB_USERNAME
        value: serviceop_migrate
        scope: RUN_TIME
      - key: DB_PASSWORD
        type: SECRET
        scope: RUN_TIME
      # DB_HOST / DB_DATABASE same as app, still SECRET
```

Helper script (local/CI when you can export migrate creds for one command only):

[`scripts/deploy/migrate_as_migrate_user.sh`](../../scripts/deploy/migrate_as_migrate_user.sh)

### What not to do

- Do not set both passwords on the web component “just in case.”  
- Do not leave `DB_USERNAME=serviceop_migrate` on queue/scheduler.  
- Do not claim filesystem wipe of the migrate password inside a long-lived web container — use a separate job instead.

---

## 4. Logging and independent revocability of `serviceop_migrate`

### DB-level connection / query logging

| Mechanism | On DigitalOcean managed MySQL |
|-----------|-------------------------------|
| MySQL general query log | **Often not practically available** to customers (managed control plane; not a toggle we should rely on) |
| Performance Insights / Insights | May show aggregate activity; not a clean per-user audit of “who ran migrate” |
| `SHOW PROCESSLIST` | Ephemeral; not durable |

**Limitation (document honestly):** Do **not** assume you can prove every `serviceop_migrate` statement from MySQL logs alone on DO managed MySQL.

### Practical alternative (required operational posture)

Treat each migrate invocation as a **CI/CD / App Platform deploy event**:

1. PRE_DEPLOY job logs (DO App → Runtime Logs / Deploy history) show start/end of `php artisan migrate`.  
2. Optionally log deploy ID / git SHA in the job command (`echo "migrate deploy=$GIT_COMMIT"`).  
3. Retain DO deploy history as the audit trail of migrate-user use.

### Independent revocation / rotation (no app downtime)

Because **runtime never uses** `serviceop_migrate` while serving traffic:

1. As admin: `ALTER USER 'serviceop_migrate'@'{APP_HOST}' IDENTIFIED BY '{NEW}';` **or** `REVOKE ALL …` / `DROP USER` then recreate.  
2. Update **only** the PRE_DEPLOY / CI secret.  
3. Web/queue/scheduler keep running on `serviceop_app` uninterrupted.

Rotating `serviceop_app` **does** require a rolling restart of runtime components (brief connection blip) — plan that separately.

---

## 5. Verification command

```bash
# Whatever the process is currently connected as (legacy / quick check)
php artisan db:verify-least-privilege --identity=current

# Assert runtime profile bounds (no DDL, no DROP)
php artisan db:verify-least-privilege --identity=runtime [--username=… --password=…]

# Assert migrate profile bounds (DDL present, no DROP)
php artisan db:verify-least-privilege --identity=migrate [--username=… --password=…]
```

| `--identity` | Asserts |
|--------------|---------|
| `current` | No `ALL PRIVILEGES` / `DROP` / admin list (does not require or forbid DDL) |
| `runtime` | Same forbidden list **plus** no `CREATE`/`ALTER`/`INDEX`/`REFERENCES`/`TRIGGER` |
| `migrate` | Same forbidden list **plus** those five DDL privileges **must** be present |

Feature tests cover FAIL under root (`current` / `runtime` / `migrate`), invalid `--identity`, and (when `CREATE USER` works) PASS on scratch runtime + migrate users plus CREATE/TRUNCATE/DROP probes.

---

## 6. Sandbox evidence (this repo machine)

| Probe | Result |
|-------|--------|
| Current app user `root` | `db:verify-least-privilege` → **FAIL** (expected) |
| Scratch two-user probe | See § “Scratch verification” below — attempted each time docs are updated; state outcome honestly |

### Scratch verification (latest — 2026-08-01)

MySQL admin **could** `CREATE USER` in this sandbox. Feature test `DbLeastPrivilegeVerifyTest::test_scratch_two_user_identities_when_admin_can_create_users` created ephemeral `sop_app_*` / `sop_mig_*` users with the Approach B grant sets, then cleaned them up.

| Check | Result |
|-------|--------|
| `db:verify-least-privilege --identity=runtime` on scratch app user | **PASS** |
| `db:verify-least-privilege --identity=migrate` on scratch migrate user | **PASS** |
| Runtime user `CREATE TABLE` | **Denied** |
| Migrate user `CREATE TABLE` + `CREATE TRIGGER` | **OK** |
| Migrate user `TRUNCATE` / `DROP TABLE` | **Denied** |
| `db:verify-least-privilege` under standing `root` (`current`/`runtime`/`migrate`) | **FAIL** (expected) |

Full `php artisan migrate` under the runtime user was not re-run as a separate artisan process in this revision; CREATE denial is the same privilege gate migrations need. Staging must still prove end-to-end migrate under `serviceop_migrate` via PRE_DEPLOY.

---

## 7. Rollback

1. If runtime rejects connections after cutover: temporarily point **runtime** components back to a known-good credential **only** long enough to restore service — prefer fixing grants on `serviceop_app`, not restoring `root` as the standing app user.  
2. If migrate PRE_DEPLOY fails: fix `serviceop_migrate` grants; runtime can keep serving on the previous schema until migrate succeeds.  
3. Break-glass admin remains offline — never re-homed into App Platform runtime env as the steady state.

---

## 8. Cutover

The literal pre-production staging cycle Trystan required is in:

**[`docs/deployment/staging_cutover_checklist.md`](staging_cutover_checklist.md)**

Follow that document item-by-item on **provisioned staging** before production.

---

## Files

| Path | Role |
|------|------|
| `app/Console/Commands/VerifyDbLeastPrivilegeCommand.php` | `db:verify-least-privilege` with `--identity` + optional `--username` |
| `tests/Feature/DbLeastPrivilegeVerifyTest.php` | Root FAIL + identity assertions |
| `scripts/deploy/migrate_as_migrate_user.sh` | One-shot local/CI migrate under migrate creds |
| `docs/deployment/database_least_privilege_migration.md` | This document |
| `docs/deployment/staging_cutover_checklist.md` | Full staging cycle checklist |

---

## Superseded: Approach A (single combined user)

The earlier recommendation to ship one user with standing DDL is **withdrawn**. Do not use Approach A for production cutover. Historical SQL that granted `CREATE/ALTER/TRIGGER` to the runtime app user must be replaced with the two-user SQL above before go-live.

---

*End of least-privilege migration guide (two-user).*
