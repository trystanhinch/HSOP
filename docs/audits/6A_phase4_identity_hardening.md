# Milestone 6A Phase 4 — Identity Hardening & Secret Hygiene

**Date:** 2026-07-31  
**Scope:** Replace Phase 1’s `ai_super_admin` token principal with a dedicated `external_review_ai` service identity; add token TTL/expiry surfacing; migrate/revoke legacy review tokens explicitly; remove hardcoded demo passwords from seeders; document repo-visible infra inventory.  
**Owner decision (verbatim):** Create a dedicated External Review AI service identity (`external_review_ai`) with its own RBAC, scopes, audit logs, API tokens, expiration, revocation, and kill switch — never inherit from `ai_super_admin`; remain strictly read-only on `/api/review-gateway/*` with Owner Review Center as the human control plane.

---

## What was built

### Task 1 — Dedicated role migration
- **ENUM:** `users.role` adds `external_review_ai` (MySQL migration). `ai_super_admin` **retained** for Command Center / `AiActionGate`.
- **Middleware:** `EnsureReviewAiAbility` now requires **role `external_review_ai` AND** an explicit `review:*` ability (defense in depth). Wrong-role denials log `wrong_role:{role}` and return `code: review_role_required`.
- **Issuance:** `php artisan review-ai:issue-token {name} [--ttl=] [--email=]` creates/attaches tokens only on the dedicated principal (`config/review_gateway.actor_email`, default `external-review-ai@serviceop.system`).
- **Login:** Interactive login blocked for both `ai_super_admin` and `external_review_ai` (`AuthController`).
- **Expiration:** Sanctum `expires_at` set on issue (default TTL **90 days**, `REVIEW_AI_TOKEN_TTL_DAYS` / `--ttl`). Review Center summary includes `tokens_nearing_expiration` (warning window default **14 days**). Scheduled: `review-ai:flag-expiring-tokens` daily 06:30 — **lists only, no auto-revoke**.
- **Legacy migration:** `php artisan review-ai:migrate-legacy-tokens` lists review:* tokens not on `external_review_ai`; **`--revoke` required to delete**.
- **Review Center:** Active token count = `external_review_ai` tokens only; identity banner; expiry column; legacy-token warning; labels updated in `ReviewCenter.jsx`.

### Task 2 — Demo password hygiene
- **Approach chosen:** `DEMO_SEED_PASSWORD` environment variable (required for interactive seeded users).  
  **Why not random-per-run:** Demo accounts need a known password for local walkthroughs; a printed random password is easy to lose and harder for CI. Env-var keeps secrets out of git while remaining repeatable when explicitly set.  
- **Production hard stop:** `DemoSeeder` and `Milestone4Seeder` throw if `app()->environment('production')`.  
- **Unset guard:** Refuse to seed interactive users if `DEMO_SEED_PASSWORD` is empty.  
- `ai_super_admin` / `external_review_ai` continue to use `Hash::make(Str::random(64))` (non-interactive).  
- `phpunit.xml` sets `DEMO_SEED_PASSWORD=phpunit-demo-seed-only` (`force="true"`) for tests that seed Milestone4.

### Task 3 — Infra inventory
See table below (repo-visible only).

---

## New / touched files

**New**
- `backend/database/migrations/2026_07_31_000001_add_external_review_ai_role.php`
- `backend/app/Services/ReviewGateway/ExternalReviewAiPrincipal.php`
- `backend/app/Console/Commands/MigrateLegacyReviewTokensCommand.php`
- `backend/app/Console/Commands/FlagExpiringReviewTokensCommand.php`
- `backend/database/seeders/Concerns/GuardsDemoSeedExecution.php`
- `backend/tests/Feature/ReviewGateway/CreatesExternalReviewAiActor.php`
- `backend/tests/Feature/ReviewGateway/ReviewGatewayIdentityHardeningTest.php`
- `backend/tests/Feature/DemoSeederGuardTest.php`
- `docs/audits/6A_phase4_identity_hardening.md` (this file)

**Modified**
- `backend/config/review_gateway.php` — actor role/email, TTL, warning days
- `backend/app/Http/Middleware/EnsureReviewAiAbility.php` — role gate
- `backend/app/Console/Commands/IssueReviewAiTokenCommand.php`
- `backend/app/Http/Controllers/Api/AdminReviewGatewayController.php`
- `backend/app/Http/Controllers/Api/AuthController.php`
- `backend/app/Http/Controllers/Api/UserController.php` / `AdminUserController.php`
- `backend/app/Models/User.php`
- `backend/routes/console.php` — schedule `review-ai:flag-expiring-tokens`
- `backend/database/seeders/DemoSeeder.php`, `Milestone4Seeder.php`
- `backend/.env.example`, `backend/phpunit.xml`
- `frontend/src/pages/ReviewCenter.jsx`
- Review Gateway Phase 1–3 tests updated to `external_review_ai`
- `backend/tests/Feature/ContentEditorAccessTest.php` — login uses `DEMO_SEED_PASSWORD`

---

## Test results

| Suite | Result |
|-------|--------|
| `ReviewGateway*` + `DemoSeederGuard*` | **33 passed** |
| Full `php artisan test` | **338 passed, 1 failed** |
| Pre-existing failure | `PublicIntakePhase1Test > unknown domain returns 404` (expects 404, gets 200) — unchanged from Phase 3 baseline (326→338 with new Phase 4 tests) |

**Phase 4 coverage includes**
- `external_review_ai` token can call Phase 1 + Phase 2 tools  
- `ai_super_admin` + crafted `review:*` abilities → **403 `review_role_required`**  
- `external_review_ai` → **403** on Command Center / AI action routes  
- Expired token rejected  
- `migrate-legacy-tokens` dry-run vs `--revoke`  
- Seeders refuse in `production`  
- Interactive login blocked for `external_review_ai`

---

## Task 2.3 — Git history findings (seeders)

Command: `git log -p -- backend/database/seeders/DemoSeeder.php backend/database/seeders/Milestone4Seeder.php`

**Findings (plain):**
1. **`DemoSeeder.php`:** Across history, interactive demo users were created with the literal `Hash::make('password')` for owner/pm/contractor/customer emails (`admin@hsop.com`, `pm@hsop.com`, `contractor@hsop.com`, `sarah@example.com`, `david@example.com`). That literal string `'password'` appeared in committed seeder source from early commits (including `122042a` lineage) until this Phase 4 change.
2. **`Milestone4Seeder.php`:** `ai_super_admin` used `Hash::make(Str::random(64))` (random per seed run; plaintext not stored in the file). Content editor (`content@hsop.com`) used `Hash::make('password')` in commits that introduced the content editor (`07e4f4d` / related).
3. No evidence in those seeder diffs of a live production password distinct from the shared demo literal `'password'`. The risk is the **well-known demo password string in git history**, not a unique stolen secret committed once.

---

## Task 2.4 — Repo-wide secret scan

- **gitleaks:** not installed in this environment. A proper scanner should be run separately by the Owner/ops.
- **Grep-based pass (application tree, excluding vendor/node_modules):**
  - **Tracked seeders:** no remaining `Hash::make('password')` after this phase.
  - **Tests:** many Feature tests still use `Hash::make('password')` / bcrypt `'password'` for ephemeral users — test-only, not production seeders.
  - **Config denylist patterns** (`sk_live_`, `sk_test_`, `whsec_`, etc.) appear as **regex strings** in `config/review_gateway.php`, not live keys.
  - **`backend/.env`:** present locally; **gitignored** (`backend/.gitignore`). Grep observed live-looking values for `OPENAI_API_KEY` (`sk-proj-…`), `STRIPE_SECRET_KEY` (`sk_test_…`), `STRIPE_WEBHOOK_SECRET` / `STRIPE_CONNECT_WEBHOOK_SECRET` (`whsec_…`). These are **not in git**; Owner should still rotate if this workspace was shared or backed up unsafely.
  - **No** `sk_live_` matches in tracked application source.

---

## Task 3 — Repo-visible infrastructure inventory

**Important:** This inventory is **only what the repository shows**. Actual account ownership (who holds DigitalOcean / Stripe / Twilio / Resend / OpenAI / GitHub billing and admin access) **cannot be confirmed from code** and must be verified by the Owner against each provider’s dashboard.

| Provider / service | Used for | Config / env keys (repo-visible) | Env-specific separation evidence |
|--------------------|----------|----------------------------------|----------------------------------|
| **DigitalOcean App Platform** | API hosting / deploy | `DEPLOY_SECRET`; comments in `.env.example` for prod URLs (`api.serviceop.ca`, App Platform notes) | Comments distinguish local vs prod URLs; no separate composer “DO” package — platform is ops, not a PHP SDK |
| **DigitalOcean Spaces** | File uploads (S3-compatible) | `FILESYSTEM_DISK`, `UPLOADS_DISK`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET`, `AWS_ENDPOINT`, `AWS_URL` (`config/filesystems.php`) | `.env.example` documents Spaces for production App Platform; local default `public` disk |
| **MySQL / database** | Primary app data | `DB_*` (`config/database.php`); tests often force MySQL `hsop_job_command` | `APP_ENV` / connection switching; phpunit defaults sqlite memory but Feature tests override to MySQL |
| **Twilio** | SMS | `TWILIO_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`, `SMS_ENABLED` (`config/services.php`) | `SMS_ENABLED=false` default in `.env.example` |
| **Resend** | Transactional email | `RESEND_API_KEY`, `MAIL_MAILER=resend`, `MAIL_FROM_*` (`config/services.php`, `config/mail.php`); `composer.json`: `resend/resend-laravel` | Mailer selectable via env |
| **Stripe** | Payments / Connect / webhooks | `PAYMENT_PROVIDER`, `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_CONNECT_WEBHOOK_SECRET` (`config/payment.php`); `composer.json`: `stripe/stripe-php` | `PAYMENT_PROVIDER=mock\|stripe`; test vs live keys are env-only (repo shows test-key shape in local `.env`, not committed) |
| **OpenAI** | Intake AI, Command Center, Whisper/Realtime | `AI_PROVIDER`, `AI_CONVERSATIONAL_PROVIDER`, `OPENAI_API_KEY`, `OPENAI_MODEL`, cost envs (`config/ai.php`) | `mock` vs `openai` providers via env |
| **GitHub** | Source / deploy remote (ops) | No runtime GitHub API keys in `config/`; repo remotes/docs reference GitHub (`usmantsz/ServiceHOP`) | Not an app runtime dependency in `composer.json` / `package.json` |
| **Google / Gmail OAuth** | Lead inbox intake | `GOOGLE_OAUTH_CLIENT_ID`, `GOOGLE_OAUTH_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `GMAIL_*` (`config/gmail.php`) | Local vs prod redirect URI commented in `.env.example` |
| **Laravel Sanctum** | API tokens | `composer.json`: `laravel/sanctum`; `config/sanctum.php` | Used for human + External Review AI tokens |
| **Frontend / public site** | React SPA, Next public site | `frontend/package.json`, `public-website/package.json` | Build tooling only; secrets via API |

---

## Assumption flags

1. **Evidence-write ability** (`review:evidence-write`) remains defined but **no write tool routes** exist under `/api/review-gateway/*` yet — role is still GET-only in production routes. Owner Review Center writes are human `owner` only.
2. **Legacy tokens** are not auto-revoked on deploy — Owner must run `review-ai:migrate-legacy-tokens --revoke` after re-issuing.
3. **`DEMO_SEED_PASSWORD`** must be set in any non-production environment that runs `DemoSeeder` / content-editor path of `Milestone4Seeder`.
4. **Account ownership** of cloud providers is out of band — see Task 3 disclaimer.
5. Phase 1 docs that say tokens attach to `ai_super_admin` are **superseded** by this phase; do not re-issue against `ai_super_admin`.

---

*End of Phase 4 audit.*
