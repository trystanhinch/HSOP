# 6AB Bugfix — Duplicate JobFailed Alert + Queue Table Collision

**Date:** 2026-08-02  
**Scope:** Only the two observations from `docs/audits/6AB_local_verification_report.md` §6.  
**Mode:** Root-cause analysis → minimal fix → regression tests → suite check.

---

## Bug 1 — Duplicate alert on permanent job failure

### Root cause (confirmed, not guessed)

**(a) Duplicate listener registration** — not the framework firing `JobFailed` twice, and not a missing idempotency key as the primary defect.

Evidence:

1. `php artisan event:list` **before** the fix listed `Illuminate\Queue\Events\JobFailed` with **two** handlers:
   - `App\Listeners\DispatchAlertOnJobFailed` (class form)
   - `App\Listeners\DispatchAlertOnJobFailed@handle` (method form)
2. Manual registration in `AppServiceProvider::boot()`:
   ```php
   Event::listen(JobFailed::class, DispatchAlertOnJobFailed::class);
   ```
3. Laravel’s listener **auto-discovery** also wires the same listener because `handle(JobFailed $event)` is type-hinted under `app/Listeners`.
4. Fail-on-old-code proof: temporarily restoring the `Event::listen` line made:
   - `test_job_failed_listener_registered_exactly_once` fail with **Count=2**
   - `test_permanent_queue_failure_creates_exactly_one_alert` fail with **got 2** alerts for one `queue:work --once` failure

Not (b): a single `queue:work --once` permanent fail produces one `JobFailed` event; the duplicate rows came from two handlers on that one event.

### Fix applied

Removed the manual `Event::listen(...)` from `AppServiceProvider::boot()`.  
Left auto-discovery as the sole registration.  
Comment in provider warns against re-adding the manual listen.

No idempotency layer was added — it would mask the double-registration rather than remove it. With one listener, one failure → one alert.

### Regression tests

`tests/Feature/Monitoring/QueueAlertAndTableCollisionFixTest.php`:

| Test | Asserts |
|------|---------|
| `test_job_failed_listener_registered_exactly_once` | `Event::getListeners(JobFailed::class)` count === 1 |
| `test_permanent_queue_failure_creates_exactly_one_alert` | Dispatch failing closure on `database`/`alert_regression`, `queue:work --once --tries=1`, exactly **one** `alerts` row with that exception marker + one `failed_jobs` row; domain `jobs` count unchanged |

**Fail-then-pass verification:**

| State | `exactly_one_alert` | `listener_exactly_once` |
|-------|---------------------|-------------------------|
| Old (manual listen restored) | **FAIL** got 2 | **FAIL** Count=2 |
| Fixed (manual listen removed) | **PASS** | **PASS** |

---

## Bug 2 — Queue table name collides with domain `jobs`

### Root cause (confirmed)

`config/queue.php` database connection used:

```php
'table' => env('DB_QUEUE_TABLE', 'jobs'),
```

ServiceOP’s domain work-order table is also named `jobs` (columns like `service_category`, `contractor_id`, … — no `payload`/`attempts`).  

Using the database queue driver against table `jobs` would read/write Laravel queue payloads into (or fail against) the business table.

**Deployment note:** Local `.env` had `QUEUE_CONNECTION=sync` (so production damage had not occurred locally). However:

- `.env.example` already had `QUEUE_CONNECTION=database`
- `.do/app.staging.yaml` sets `QUEUE_CONNECTION=database`
- `config/queue.php` default is `env('QUEUE_CONNECTION', 'database')`

So staging/example paths were already aimed at the database driver — fixing the table name before cutover is required.

### Fix applied

| Change | Detail |
|--------|--------|
| `config/queue.php` | Default table → `queue_jobs` (`env('DB_QUEUE_TABLE', 'queue_jobs')`) |
| Migration | `2026_08_02_000001_create_queue_jobs_table.php` — standard Laravel queue schema |
| `.env.example` | `DB_QUEUE_TABLE=queue_jobs` + comment that it must not be `jobs` |
| `.do/app.staging.yaml` | `DB_QUEUE_TABLE=queue_jobs` next to `QUEUE_CONNECTION=database` |

Domain `jobs` table **not** renamed.

### Regression test

`test_database_queue_uses_queue_jobs_table_not_domain_jobs`:

- Config table is `queue_jobs`
- Schema: `queue_jobs` has `payload`/`attempts`; domain `jobs` has `service_category`, not `payload`
- Dispatch on database connection increments `queue_jobs` only; domain `Job` model count unchanged
- `queue:work` drains `queue_jobs` without touching domain rows

---

## Files touched

- `backend/app/Providers/AppServiceProvider.php` — remove duplicate `Event::listen`
- `backend/config/queue.php` — `queue_jobs` default
- `backend/database/migrations/2026_08_02_000001_create_queue_jobs_table.php` — new
- `backend/.env.example` — `DB_QUEUE_TABLE`
- `.do/app.staging.yaml` — `DB_QUEUE_TABLE`
- `backend/tests/Feature/Monitoring/QueueAlertAndTableCollisionFixTest.php` — new (3 tests)

---

## Suite results

**Command:** `php artisan test`  
**Against baseline:** verification report **417 passed / 1 failed**

| Metric | This run |
|--------|----------|
| Passed | **420** (+3 new regression tests) |
| Failed | **1** (`PublicIntakePhase1Test > unknown domain returns 404` — unchanged) |
| Skipped | **0** |

```
Tests:    1 failed, 420 passed (2530 assertions)
Duration: 124.65s
```

No new failures beyond the known PublicIntake issue.

---

## Ops follow-up (not done in this fix)

- Set `DB_QUEUE_TABLE=queue_jobs` on any live App Platform / production env that uses `QUEUE_CONNECTION=database`
- Run migration `2026_08_02_000001_create_queue_jobs_table` before switching workers to database queue
- Local `.env` may still be `sync`; that is fine — when switching to database, use `queue_jobs`

---

*End of bugfix audit.*
