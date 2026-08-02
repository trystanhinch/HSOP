# Milestone 6A Phase 8 — Staging Foundation (6A.2 Resettable Staging)

**Date:** 2026-08-01  
**Scope:** Infrastructure-as-code / config artifacts, reset tooling, fail-closed provider checks, Basic Auth + noindex, staging banner.  
**Out of scope:** Live DO provisioning, fault injection, production data anonymization/subset copy.

---

## Access model (confirm)

This sandbox **does not** have DigitalOcean credentials. Provisioning must run under the **owner-controlled** DO account (Trystan’s team, or a developer temporarily granted access). Artifacts produced here are inputs to that ops step — they do not create cloud resources by themselves.

---

## What was built

### 1. App Platform spec
- [`.do/app.staging.yaml`](../../.do/app.staging.yaml) — separate staging app (`serviceop-staging`), API service, queue worker, scheduler job placeholder, secret bindings, `STAGING_MODE=true`, SMS off, mail=`log`, Stripe secret as SECRET (must be `sk_test_*`).
- Explicit comments: **not** a preview/branch of production; separate MySQL + Spaces required.

### 2. Reset tooling
- `php artisan staging:reset --force` — `migrate:fresh` + Settings / Milestone4 / MessageTemplate / Demo seeders.
- Refuses unless **both** `config('app.staging_mode') === true` **and** `!app()->environment('production')`.
- Requires `--force` and `DEMO_SEED_PASSWORD` (respects existing seeder production guards).

### 3. Fail-closed provider isolation
- `StagingIsolationGuard::assertBootSafe()` runs from `AppServiceProvider::boot` when `staging_mode` is true.
- Live Stripe (`sk_live_*`) → **fatal RuntimeException** (app will not boot).
- **OpenAI:** no live/test key prefix — skipped (documented).
- **Twilio:** Account SIDs do not encode live vs test — boot does not pattern-match SIDs; `staging:verify-isolation` fails if `SMS_ENABLED=true` without allowlist.

### 4. Communication interception verification
- `php artisan staging:verify-isolation` checks: `STAGING_MODE`, not production env, Stripe live key, SMS unsafe, mailer not sandbox, DB host/name vs forbidden production identifiers, Basic Auth configured.

### 5. Access restriction
- `RequireStagingBasicAuth` — global middleware; **no-op** when `staging_mode` is false (zero prod impact).
- `AddStagingNoIndexHeaders` — `X-Robots-Tag: noindex, nofollow, noarchive` when staging.
- Frontend `StagingBanner` + `noindex` meta when `VITE_STAGING_MODE=true`; `EnvironmentBadge` honors the same flag.

### 6. Documentation
- [`docs/deployment/staging_setup.md`](../deployment/staging_setup.md) — full provisioning + env var names + reset/verify + Basic Auth.

---

## Files touched

| Path | Role |
|------|------|
| `.do/app.staging.yaml` | DO App Platform staging spec |
| `config/app.php` | `staging_mode` |
| `config/staging.php` | Isolation / Basic Auth / forbidden prod DB ids |
| `app/Services/Staging/StagingIsolationGuard.php` | Boot + verify checks |
| `app/Console/Commands/StagingResetCommand.php` | `staging:reset` |
| `app/Console/Commands/StagingVerifyIsolationCommand.php` | `staging:verify-isolation` |
| `app/Http/Middleware/RequireStagingBasicAuth.php` | Basic Auth gate |
| `app/Http/Middleware/AddStagingNoIndexHeaders.php` | Robots header |
| `app/Providers/AppServiceProvider.php` | Boot fail-closed |
| `bootstrap/app.php` | Register staging middleware |
| `backend/.env.example` | Staging var names |
| `frontend/.../StagingBanner.jsx`, `main.jsx`, `EnvironmentBadge.jsx`, `.env.development` | Banner / noindex |
| `docs/deployment/staging_setup.md` | Ops guide |
| `tests/Feature/Staging/StagingFoundationTest.php` | Safety tests |

---

## Test results

**New:** `StagingFoundationTest` — **7 passed** (reset guards ×2, boot fail-closed live/test Stripe, verify-isolation fail/pass, Basic Auth on/off, `--force` required).

**Full suite:** **381 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`). Delta vs Phase 7 baseline (374): **+7**.

---

## Owner/Ops action required (cannot be done in this sandbox)

1. Authenticate `doctl` to the **owner** DigitalOcean account.  
2. Create a **separate** App from `.do/app.staging.yaml`.  
3. Provision **separate** Managed MySQL + Spaces; bind secrets (never paste production values).  
4. Set `STAGING_MODE=true`, Basic Auth secrets, `DEMO_SEED_PASSWORD`, Stripe **test** keys only.  
5. Run `php artisan staging:verify-isolation` then `php artisan staging:reset --force`.  
6. Deploy SPA with `VITE_STAGING_MODE=true`.  
7. Confirm Basic Auth + staging banner in a browser.

---

## Open questions / assumption flags

1. **Data strategy:** Synthetic seeders only for now. Sanitized production subset / anonymization is **not** built — needs Trystan decision (also flagged in Phase 0).  
2. **Twilio:** No reliable live-key prefix; isolation = `SMS_ENABLED=false` (or allowlisted SID).  
3. **OpenAI:** No live/test distinction in key format — use a dedicated staging key or `AI_PROVIDER=mock`.  
4. **Basic Auth exemptions:** `/up` and `api/stripe/webhook` by default (health + test webhooks).  
5. **Scheduler in app spec:** Documented as its own component — adjust DO job/worker shape to match final plan/cost tier.  
6. **Repo/branch** in the YAML (`usmantsz/ServiceHOP`, `milestone-5-dev`) may need updating for the branch Trystan wants staging to track.

---

*End of Phase 8 audit.*
