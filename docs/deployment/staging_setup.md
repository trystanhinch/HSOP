# Staging environment setup (Milestone 6A.2)

This guide provisions a **separate** DigitalOcean App Platform app for ServiceOP staging. It is **not** a preview/branch deploy of production.

**Access model:** Provisioning requires the **owner-controlled** DigitalOcean account. This repository does not contain DO credentials. Trystan’s team (or a developer granted temporary DO access) runs the steps below.

---

## 1. Create a SEPARATE DO App from the spec

Spec file: [`.do/app.staging.yaml`](../../.do/app.staging.yaml)

```bash
# From repo root (requires doctl authenticated to the OWNER account)
doctl apps create --spec .do/app.staging.yaml
```

Or: DigitalOcean UI → Apps → Create → “App Spec” → paste/upload `.do/app.staging.yaml`.

**Do not** attach this as a component of the production app. Name it distinctly (e.g. `serviceop-staging`).

---

## 2. Attach separate managed resources

| Resource | Requirement |
|----------|-------------|
| Managed MySQL | **New** cluster/database — never point staging `DB_*` at production |
| Spaces bucket | **New** bucket (e.g. `serviceop-staging-media`) — never reuse production bucket |
| Scheduler | Staging must run its **own** `schedule:work` / worker — never share production cron |

Update the app’s encrypted env vars (DO UI or `doctl apps update-spec`) for `DB_*` and Spaces keys after resources exist.

---

## 3. Required environment variables (names only)

Set these on the **staging** app only. **Never copy-paste values from production.**

### Must be staging-specific
| Variable | Notes |
|----------|--------|
| `STAGING_MODE` | Must be `true` |
| `APP_ENV` | `staging` (alone is **not** enough without `STAGING_MODE`) |
| `APP_KEY` | Generate fresh (`php artisan key:generate --show`) |
| `APP_URL` / `FRONTEND_URL` | Staging hostnames |
| `DB_HOST` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Staging MySQL only |
| `AWS_BUCKET` / Spaces keys | Staging bucket only |
| `STRIPE_SECRET_KEY` | **`sk_test_…` only** — boot **refuses** `sk_live_` |
| `STRIPE_PUBLISHABLE_KEY` / webhook secrets | Test-mode endpoints |
| `DEMO_SEED_PASSWORD` | Required for `staging:reset` seeders |
| `STAGING_BASIC_AUTH_USER` / `STAGING_BASIC_AUTH_PASSWORD` | HTTP Basic Auth gate |
| `STAGING_FORBIDDEN_PROD_DB_NAMES` | Production DB names to reject |
| `STAGING_FORBIDDEN_PROD_DB_HOSTS` | Production DB hosts to reject |

### Safe defaults for staging
| Variable | Recommended staging value |
|----------|---------------------------|
| `SMS_ENABLED` | `false` |
| `MAIL_MAILER` | `log` (or `array`) |
| `AI_PROVIDER` | `mock` (or staging OpenAI key — OpenAI has no live/test prefix) |
| `PAYMENT_PROVIDER` | `stripe` with test keys, or `mock` |
| `VITE_STAGING_MODE` | `true` on the SPA build |

### Frontend
Build the SPA with `VITE_STAGING_MODE=true` and `VITE_API_URL` pointing at the staging API. This enables the sticky **“STAGING — Not Production”** banner and `noindex` meta.

---

## 4. HTTP Basic Auth

When `STAGING_MODE=true`, almost every request requires HTTP Basic Auth (`STAGING_BASIC_AUTH_*`).

Default exemptions (override with `STAGING_BASIC_AUTH_EXCEPT`):
- `up` — DO health checks
- `api/stripe/webhook` — Stripe test webhooks (configure Stripe CLI / dashboard accordingly)

Browser users: enter Basic Auth credentials once, then use normal ServiceOP login.

---

## 5. After first deploy — verify, then reset

```bash
# SSH/console into the staging app, or use DO “Console” / one-off job:

php artisan staging:verify-isolation
# Expect: exit 0. Fix any FAIL rows before proceeding.

php artisan staging:reset --force
# migrate:fresh + SettingsSeeder, Milestone4Seeder, MessageTemplateSeeder, DemoSeeder
# Requires DEMO_SEED_PASSWORD and STAGING_MODE=true; refuses APP_ENV=production.
```

Re-verify after reset:

```bash
php artisan staging:verify-isolation
```

---

## 6. Safety behavior (already in application code)

| Guard | Behavior |
|-------|----------|
| `STAGING_MODE` + `sk_live_*` | **Fatal boot** (`StagingIsolationGuard::assertBootSafe`) |
| `staging:reset` without `STAGING_MODE` | Refused |
| `staging:reset` with `APP_ENV=production` | Refused (independent of `STAGING_MODE`) |
| `staging:verify-isolation` | Flags live Stripe, unsafe SMS/mail, prod DB identifiers, missing Basic Auth |
| Responses | `X-Robots-Tag: noindex, nofollow, noarchive` when staging |
| Frontend | Staging banner when `VITE_STAGING_MODE=true` |

---

## 7. Open question — data strategy

This setup seeds **synthetic demo data** via existing seeders. A sanitized production subset / anonymization pipeline was **not** specified in the staging proposal and is **not** included here. Decide with Trystan before any production data copy.

---

## 8. Ops checklist

1. [ ] Owner DO account authenticated (`doctl auth list`)
2. [ ] Create app from `.do/app.staging.yaml` (separate from production)
3. [ ] Create staging MySQL + Spaces; bind secrets
4. [ ] Set all staging-specific env vars (never paste prod secrets)
5. [ ] Confirm Stripe **test** keys only
6. [ ] `php artisan staging:verify-isolation` → pass
7. [ ] `php artisan staging:reset --force`
8. [ ] Deploy SPA with `VITE_STAGING_MODE=true`
9. [ ] Confirm Basic Auth prompt + staging banner in browser
10. [ ] Point Stripe test webhooks at staging (if using Stripe on staging)
