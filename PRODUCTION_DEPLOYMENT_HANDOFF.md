# HSOP Job Command — Production Deployment & Handoff Package

**Prepared for:** HSOP / ServiceOP (serviceop.ca)  
**Date:** June 2026  
**Status:** Ready for production deployment pending credentials and infrastructure access  
**Repository:** https://github.com/usmantsz/HSOP

---

## Purpose of This Document

This document answers the deployment information request so HSOP/ServiceOP can proceed with production deployment on **DigitalOcean** and **Cloudflare** (`serviceop.ca`). It also outlines what should be transferred to the company for full long-term ownership of the software.

---

## 1. Frontend Framework

| Item | Detail |
|------|--------|
| **Framework** | **React** (Single Page Application) |
| **Build tool** | **Vite** 8.x |
| **Styling** | **Tailwind CSS** 4.x |
| **Routing** | **React Router** 7.x |
| **HTTP client** | Axios |
| **Icons** | lucide-react |
| **Auth** | Laravel Sanctum (Bearer token stored in browser) |

**Production output:** Static files only (`frontend/dist/` — HTML, JS, CSS). No Node.js server is required in production; Node is only needed at build time (`npm run build`).

**Source location:** `/frontend`

---

## 2. Backend Framework

| Item | Detail |
|------|--------|
| **Framework** | **Laravel 12** (PHP API) |
| **PHP version** | **8.2+** required |
| **Authentication** | Laravel Sanctum (token-based API auth) |
| **SMS** | Twilio SDK (`twilio/sdk`) |
| **Email** | Laravel Mail (SMTP) |

**Production output:** PHP application serving REST API at `/api/*`.

**Source location:** `/backend`

**Post-deploy commands (each release):**
```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
```

---

## 3. Database Required

| Item | Detail |
|------|--------|
| **Database** | **MySQL 8** |
| **ORM** | Laravel Eloquent |
| **Migrations** | `backend/database/migrations/` (version-controlled schema) |
| **Seeders** | `backend/database/seeders/` (demo data + default settings) |

SQLite is referenced in local `.env.example` for quick local setup only. **Production must use MySQL.**

**Key tables:** users, leads, jobs, quotes, invoices, payments, payouts, audit_logs, sms_logs, email_logs, settings, job_updates, messages, contractors, customers, and related workflow tables.

**Existing live data:** A MySQL dump from the current staging server (`hsop_job_command-313931a52b`) can be imported into the new Managed Database, or the team can start fresh and re-seed.

---

## 4. GitHub Repository Details

| Item | Detail |
|------|--------|
| **Repository URL** | https://github.com/usmantsz/HSOP |
| **Default branch** | `main` |
| **Previous state** | Code lived on a local developer machine and was deployed manually (zip/FTP) to `alphaarraytechnologies.com` — it was **not** previously in version control |
| **Current state** | Source code is being committed to the repository above |

### Recommended ownership transfer (important)

As HSOP/ServiceOP becomes a production company, **the repository should be owned by the company**, not an individual developer account. Recommended steps:

1. Create a **GitHub Organization** (e.g. `serviceop-ca` or `hsop-canada`)
2. **Transfer** the `HSOP` repository from `usmantsz` to that organization, **or** fork/import and make the org repo the canonical source
3. Grant the development team **collaborator** access only as needed — the company account holds **admin/owner** rights
4. Enable branch protection on `main` for production releases

**What the company should own:**
- Full GitHub repository (owner role)
- Complete source code (`frontend/` + `backend/`)
- Database schema and migrations (in repo)
- This deployment document and `scripts/DEPLOY-LIVE.md`
- All production environment variables (stored in DigitalOcean, not in the repo)
- Third-party accounts: Twilio, SMTP/email provider, DigitalOcean, Cloudflare

---

## 5. Recommended DigitalOcean Resources

For `serviceop.ca` on DigitalOcean with Cloudflare DNS:

### Recommended architecture (simplest for launch)

| Resource | Purpose | Notes |
|----------|---------|-------|
| **App Platform — Static Site** | Host React frontend (`frontend/dist`) | Low cost, auto SSL via Cloudflare |
| **App Platform — Web Service (PHP)** | Run Laravel API | PHP 8.2+, build: `composer install` |
| **Managed MySQL 8** | Production database | Automated backups, patching |
| **Cloudflare DNS** | `serviceop.ca` DNS + SSL | Proxy in front of DO |

### Suggested domain layout

| Hostname | Points to | Purpose |
|----------|-----------|---------|
| `app.serviceop.ca` (or `serviceop.ca`) | App Platform Static Site | Staff & customer web UI |
| `api.serviceop.ca` | App Platform Web Service | Laravel REST API |

### Optional (scale later)

| Resource | When to add |
|----------|-------------|
| **DigitalOcean Spaces** | When file uploads (contractor photos) need CDN-backed storage |
| **Redis** | If queue workers or caching are added later |
| **Droplet + Nginx** | Alternative to App Platform if you want full server control |

### Not required at launch

- Kubernetes
- Separate queue worker (app uses `QUEUE_CONNECTION=sync` today)
- Redis (app uses file cache in production config)

### Cloudflare setup

- Point `api.serviceop.ca` → DigitalOcean API component
- Point `app.serviceop.ca` → DigitalOcean static site
- SSL mode: **Full (strict)** recommended once DO certificates are in place

---

## 6. Environment Variables, API Keys & Credentials Needed

The development team will need the following **from HSOP/ServiceOP** before production deployment. **Never commit these to GitHub** — set them in DigitalOcean App Platform environment settings or a secure secrets manager.

### A. Infrastructure access

- [ ] DigitalOcean account access (or team invite with App Platform + Database permissions)
- [ ] Cloudflare access to `serviceop.ca` DNS
- [ ] Confirmation of production URLs (e.g. `https://app.serviceop.ca` and `https://api.serviceop.ca`)

### B. Laravel backend (`backend/.env` on API service)

#### Core application
```env
APP_NAME="HSOP Job Command"
APP_ENV=production
APP_KEY=                          # Generate: php artisan key:generate --show
APP_DEBUG=false
APP_URL=https://api.serviceop.ca
FRONTEND_URL=https://app.serviceop.ca
LOG_CHANNEL=stack
LOG_LEVEL=error
```

#### Database (from DigitalOcean Managed MySQL)
```env
DB_CONNECTION=mysql
DB_HOST=                          # DO managed DB hostname
DB_PORT=25060                     # DO default; may be 3306
DB_DATABASE=hsop_production
DB_USERNAME=
DB_PASSWORD=
```

#### Authentication & CORS
```env
SANCTUM_STATEFUL_DOMAINS=app.serviceop.ca,serviceop.ca
CORS_ALLOWED_ORIGINS=https://app.serviceop.ca,https://serviceop.ca
SESSION_DRIVER=database
SESSION_DOMAIN=.serviceop.ca
```

#### File storage
```env
FILESYSTEM_DISK=public
QUEUE_CONNECTION=sync
CACHE_STORE=file
BROADCAST_CONNECTION=log
```

#### Email (SMTP) — required for real notifications
```env
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@serviceop.ca
MAIL_FROM_NAME="HSOP Job Command"
```

#### SMS (Twilio) — required for real SMS
```env
TWILIO_SID=
TWILIO_AUTH_TOKEN=
TWILIO_FROM_NUMBER=+1xxxxxxxxxx    # E.164 format
SMS_ENABLED=true
```

#### One-time deploy helper (optional)
```env
DEPLOY_SECRET=                     # Strong random string for /deploy/migrate/{secret}
```

#### DigitalOcean Spaces (optional — if using object storage for uploads)
```env
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=tor1
AWS_BUCKET=
AWS_ENDPOINT=https://tor1.digitaloceanspaces.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### C. Frontend build-time variables

Set **before** `npm run build` in App Platform or in `frontend/.env.production`:

```env
VITE_API_URL=https://api.serviceop.ca/api
VITE_STORAGE_URL=https://api.serviceop.ca
```

### D. Third-party service accounts (company-owned)

These should be registered under **HSOP/ServiceOP**, not a personal developer account:

| Service | Purpose | What we need |
|---------|---------|--------------|
| **Twilio** | SMS notifications | Account SID, Auth Token, verified sending phone number |
| **Email provider** | SMTP (SendGrid, Mailgun, Google Workspace, etc.) | SMTP host, port, username, password |
| **DigitalOcean** | Hosting + database | Account access |
| **Cloudflare** | DNS + SSL for serviceop.ca | DNS access |
| **GitHub** | Source code | Org-owned repo (see Section 4) |

### E. Post-deploy verification checklist

After credentials are set and the app is deployed:

1. Admin login works
2. Create a test lead through the UI
3. Quote pricing formula: contractor price ÷ 0.80 = customer subtotal (ratio must be **1.25**)
4. One real SMS arrives on a test phone
5. One real email arrives in a test inbox
6. File upload (contractor photo) saves and displays
7. `php artisan migrate --force` completes on production database

---

## API Overview (for documentation)

- **Base URL:** `https://api.serviceop.ca/api`
- **Auth:** `POST /api/login` → returns Bearer token; include `Authorization: Bearer {token}` on subsequent requests
- **Public quote links:** `GET /api/quote/view/{customer_token}` (no auth)
- **Roles:** owner, pm, contractor, customer (role-based access on job/lead data)

Full route list: `backend/routes/api.php`

---

## Deployment Instructions (summary)

Detailed steps: `scripts/DEPLOY-LIVE.md`

1. Connect GitHub repo `usmantsz/HSOP` to DigitalOcean App Platform
2. **Static Site component:** build `cd frontend && npm ci && npm run build`, output `frontend/dist`
3. **Web Service component:** root `backend/`, run `composer install --no-dev`, start via PHP/Apache/Nginx as per DO Laravel guide
4. Attach **Managed MySQL** and set `DB_*` env vars
5. Set all env vars from Section 6
6. Run migrations: `php artisan migrate --force` (SSH or deploy URL)
7. Run `php artisan storage:link`
8. Point Cloudflare DNS to App Platform URLs
9. Verify smoke tests (Section 6E)

---

## Milestone 3 Handoff Status (honest summary)

| Area | Code complete | Production verified |
|------|---------------|---------------------|
| Core workflow (leads → jobs → quotes → invoices → payouts) | Yes | Locally verified |
| 80/20 pricing formula (÷ 0.80) | Yes | Ratio 1.25 confirmed |
| SMS notifications | Wired (14 triggers) | **Needs real Twilio credentials** |
| Email notifications | Wired (12 triggers) | **Needs real SMTP credentials** |
| Job completion workflow | Yes | API verified |
| Settings (GST, markup) | Yes | API verified |
| Admin dashboard KPIs | Yes | Counts match database |
| Search & filters | Yes | API verified |
| Security (403 on cross-customer access) | Yes | Tested |
| Live deploy to serviceop.ca | Pending | Awaiting this deployment |

---

## What HSOP/ServiceOP Should Request as Final Deliverables

To retain complete control regardless of future developer changes:

1. **GitHub repository ownership** (organization-owned)
2. **Complete source code** (this repo)
3. **Database migrations** (`backend/database/migrations/`)
4. **API documentation** (routes + this document)
5. **Deployment instructions** (this file + `scripts/DEPLOY-LIVE.md`)
6. **Environment variable list** (Section 6 above)
7. **Third-party accounts** transferred to company ownership (Twilio, email, DO, Cloudflare)
8. **Production database backup** after go-live
9. **Admin credentials** for production (stored securely by the company)

---

## Contact / Next Steps

Once HSOP/ServiceOP provides:

1. DigitalOcean + Cloudflare access  
2. Managed MySQL connection details  
3. Twilio + SMTP credentials  
4. Confirmed production URLs (`app.serviceop.ca`, `api.serviceop.ca`)  
5. GitHub org transfer plan (recommended)

…the development team can deploy to production and run the post-deploy verification checklist.

---

*Document version: 1.0 — HSOP Job Command Milestone 3 Production Handoff*
