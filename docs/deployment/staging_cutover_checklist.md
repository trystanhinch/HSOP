# Staging cutover checklist — two-user least-privilege (Trystan-required)

**Date:** 2026-08-01  
**Audience:** Trystan’s team / whoever has owner DO access.  
**Prerequisite docs:**  
- [`database_least_privilege_migration.md`](database_least_privilege_migration.md) — SQL + credential swapping  
- [`staging_setup.md`](staging_setup.md) — provision separate staging app/resources  

**Honesty rule:** Items marked **SANDBOX** can be exercised on the developer MySQL used for this repo. Items marked **STAGING-ONLY** require the provisioned staging App Platform app + managed MySQL + workers. Do not tick STAGING-ONLY as done from the sandbox alone.

Replace `{DB_NAME}`, `{APP_HOST}`, passwords, and app IDs with staging values.

---

## 0. Preflight

| # | Step | How to execute / verify | Where |
|---|------|-------------------------|--------|
| 0.1 | Staging app exists **separate** from production | `doctl apps list` — distinct app name (e.g. `serviceop-staging`); DB host ≠ production | STAGING-ONLY |
| 0.2 | Staging MySQL schema name known | DO Managed DB → Databases → copy name into `{DB_NAME}` | STAGING-ONLY |
| 0.3 | Break-glass admin credentials available offline | Confirm team vault has admin/`root` or DO master user — **not** in App Platform runtime env | STAGING-ONLY |
| 0.4 | Two secrets generated | Generate distinct `{RUNTIME_PASSWORD}` and `{MIGRATE_PASSWORD}`; store in vault; never commit | Both |

---

## 1. Fresh deployment identity setup

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 1.1 | Create users (admin session) | Run the **Primary SQL** block in `database_least_privilege_migration.md` §2 against staging MySQL | `SHOW GRANTS FOR 'serviceop_app'@'{APP_HOST}';` and same for `serviceop_migrate` | STAGING-ONLY (SQL can be dry-run in SANDBOX) |
| 1.2 | Point **runtime** components at `serviceop_app` | DO App → web + queue + scheduler: set `DB_USERNAME=serviceop_app`, `DB_PASSWORD={RUNTIME_PASSWORD}` | Env UI shows runtime user only — **no** migrate password on those components | STAGING-ONLY |
| 1.3 | Point **PRE_DEPLOY migrate job** at `serviceop_migrate` | Job `db-migrate` (or equivalent): `DB_USERNAME=serviceop_migrate`, migrate password SECRET; `run_command` includes `php artisan migrate --force` | Job env ≠ web env for `DB_USERNAME` | STAGING-ONLY |
| 1.4 | Confirm root not in app env | Search App Platform env for `root` / old master user | Zero matches on web/queue/scheduler/migrate job (admin only in vault) | STAGING-ONLY |

---

## 2. All pending migrations

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 2.1 | Run migrate as migrate user | Trigger deploy so PRE_DEPLOY runs, **or** from a one-shot shell with migrate env: `php artisan migrate --force` | Exit 0; deploy logs show migrate output | STAGING-ONLY |
| 2.2 | Verify migrate identity grants | `php artisan db:verify-least-privilege --identity=migrate` (on migrate job or with `--username=serviceop_migrate --password=…`) | Output: `LEAST-PRIVILEGE CHECK: PASS — identity [migrate]` | STAGING-ONLY / SANDBOX if scratch users exist |
| 2.3 | Ledger triggers present | `php artisan ledger:verify-triggers` | Command reports triggers OK | STAGING-ONLY |
| 2.4 | Confirm runtime cannot migrate | Temporarily (break-glass test): connect as `serviceop_app` and run `php artisan migrate --force` against a **scratch** schema or observe failure on DDL | Expect MySQL 1142 / migrate failure on `CREATE`/`ALTER` | STAGING-ONLY |

---

## 3. Normal application workflows

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 3.1 | Health | `curl -sS -o /dev/null -w "%{http_code}" https://{STAGING_HOST}/up` (or `/api/...` health if used) | HTTP 200 | STAGING-ONLY |
| 3.2 | Owner login | Browser → staging login as owner | Session established; dashboard loads | STAGING-ONLY |
| 3.3 | Create lead | UI or `POST /api/leads` as owner/pm | Lead row visible in UI / DB | STAGING-ONLY |
| 3.4 | Convert / open job | Happy-path lead → job | Job detail loads | STAGING-ONLY |
| 3.5 | Runtime least-privilege | On web dyno or with runtime creds: `php artisan db:verify-least-privilege --identity=runtime` | `PASS — identity [runtime]` | STAGING-ONLY |

---

## 4. Queue and scheduler activity

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 4.1 | Queue worker running | DO component running `php artisan queue:work …` with `DB_USERNAME=serviceop_app` | Component healthy; no auth errors in logs | STAGING-ONLY |
| 4.2 | Enqueue + process job | Trigger a known queued action (e.g. notification / ops report) or `php artisan queue:work --once` after dispatching a test job | Job leaves `jobs` table / appears in `failed_jobs` only on real failure | STAGING-ONLY |
| 4.3 | Scheduler | Staging-only `schedule:work` or cron equivalent | `php artisan schedule:list`; logs show a tick within interval | STAGING-ONLY |
| 4.4 | Gmail / monitoring schedules (if enabled) | Confirm staging flags allow or skip external calls | No production mailbox touched; staging logs only | STAGING-ONLY |

---

## 5. File and AI-related workflows touching the database

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 5.1 | Media upload | Upload a photo on a staging lead/job (Spaces staging bucket) | File metadata row written; object in **staging** bucket only | STAGING-ONLY |
| 5.2 | AI action log path | Exercise a mocked AI path (`AI_PROVIDER=mock`) that writes `ai_action_logs` | New row; no live OpenAI required | STAGING-ONLY |
| 5.3 | Learning gateway (optional) | With learning kill switch OFF and a learning token: `GET /api/learning-gateway/ping` | 200; row in `learning_gateway_access_logs` | STAGING-ONLY |
| 5.4 | Confirm Spaces ≠ production | Compare `AWS_BUCKET` env on staging vs production | Different bucket names | STAGING-ONLY |

---

## 6. Rollback procedure

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 6.1 | Document rollback trigger | If web cannot connect after user swap: restore **runtime** `DB_PASSWORD`/`DB_USERNAME` to last known-good **least-privilege** app user (or temporarily break-glass), redeploy | App serves again | STAGING-ONLY |
| 6.2 | Prefer grant fix over root | As admin: `SHOW GRANTS` / grant missing priv on `serviceop_app` only | `db:verify-least-privilege --identity=runtime` still PASS (no DROP) | STAGING-ONLY |
| 6.3 | Migrate-job failure isolation | Break migrate password deliberately on PRE_DEPLOY once in staging | Deploy fails at migrate; **runtime** keeps prior release if DO retains old containers — confirm behaviour for your app | STAGING-ONLY |
| 6.4 | Never leave root as steady runtime | After recovery, confirm env is back to `serviceop_app` | No `root` in runtime env | STAGING-ONLY |

---

## 7. Backup and restoration check

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 7.1 | Take staging backup | DO Managed DB → Backups / create manual backup (or `doctl databases backups …` if available) | Backup visible in DO UI with timestamp | STAGING-ONLY |
| 7.2 | Restore to a **scratch** DB or fork | Restore backup to a temporary database (not production) | Restore completes; row counts smoke-check | STAGING-ONLY |
| 7.3 | App still on least-privilege after restore | Point a throwaway config at restored DB with `serviceop_app` | App connects; verify command PASS | STAGING-ONLY |

**SANDBOX:** Full DO backup/restore **cannot** be validated from the local `hsop_job_command` sandbox. Do not tick 7.x from local-only work.

---

## 8. Runtime user cannot perform prohibited admin actions

| # | Step | Exact command / action | Verify | Where |
|---|------|------------------------|--------|--------|
| 8.1 | Verify runtime profile | `php artisan db:verify-least-privilege --identity=runtime --username=serviceop_app --password=…` | PASS; DDL checks PASS (absent) | STAGING-ONLY / SANDBOX scratch |
| 8.2 | TRUNCATE denied | As `serviceop_app`: `TRUNCATE TABLE some_noncritical_scratch;` | Error 1142 / DROP required | STAGING-ONLY / SANDBOX |
| 8.3 | DROP denied | As `serviceop_app`: `DROP TABLE some_scratch;` | Denied | STAGING-ONLY / SANDBOX |
| 8.4 | CREATE denied | As `serviceop_app`: `CREATE TABLE priv_probe (id INT);` | Denied | STAGING-ONLY / SANDBOX |
| 8.5 | Migrate user still no DROP | As `serviceop_migrate`: `DROP TABLE …` / `TRUNCATE …` | Denied | STAGING-ONLY / SANDBOX |
| 8.6 | Migrate user can DDL | As `serviceop_migrate`: create/drop **trigger** on a scratch table or run `ledger:reapply-triggers` | Succeeds | STAGING-ONLY / SANDBOX |

---

## 9. Sign-off

| Role | Name | Date | Notes |
|------|------|------|-------|
| Executed by | | | |
| Reviewed by (Owner) | | | Staging cycle complete; production cutover approved / blocked |

**Production cutover is blocked** until every **STAGING-ONLY** row above is checked on the real staging environment.

---

## What this sandbox already proved (do not confuse with staging)

| Item | Status in developer sandbox |
|------|------------------------------|
| `db:verify-least-privilege` FAIL on `root` | Yes |
| Command `--identity=runtime\|migrate\|current` | Yes (unit/feature coverage) |
| Two-user SQL text reviewed as primary path | Yes (documentation) |
| Scratch `serviceop_app` / `serviceop_migrate` CREATE + probes | **Yes** (ephemeral users in `DbLeastPrivilegeVerifyTest`; PASS — see migration doc §6) |
| Real DO PRE_DEPLOY, queue, scheduler, Spaces, backup/restore | **Not** done here — STAGING-ONLY |

---

*End of staging cutover checklist.*
