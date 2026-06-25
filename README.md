# HSOP Job Command

**Home Service Operating Platform** — Phase 1 Foundation

A responsive web app for managing home service business operations: leads, jobs, contractors, customers, quotes, invoices, payouts, and more.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Frontend | React 18 + Vite + Tailwind CSS |
| Backend | Laravel 12 API (PHP 8.2+) |
| Database | MySQL 8 |
| Auth | Laravel Sanctum (token-based) |
| HTTP Client | Axios |
| Icons | lucide-react |
| Routing | react-router-dom v6 |

## Project Structure

```
hsop-job-command/
├── frontend/     # React + Vite SPA
├── backend/      # Laravel API
└── README.md
```

## Prerequisites

- PHP 8.2+
- Composer
- Node.js 18+
- MySQL 8 (e.g. XAMPP, WAMP, or standalone MySQL)
- npm

## Database Setup

1. Start MySQL.
2. Create the database:

```sql
CREATE DATABASE hsop_job_command CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

3. Update `backend/.env` if your MySQL credentials differ:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hsop_job_command
DB_USERNAME=root
DB_PASSWORD=
```

## Backend Setup

```bash
cd backend
composer install
cp .env.example .env   # if .env does not exist
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

API runs at **http://localhost:8000**

> **Note:** Ensure port 8000 is not in use by another application before starting the API server.

## Frontend Setup

```bash
cd frontend
npm install
npm run dev
```

App runs at **http://localhost:5173**

## Test Accounts (Demo)

| Role | Email | Password | What they see |
|------|-------|----------|---------------|
| Admin/Owner | admin@hsop.com | password | Everything — 4 leads, 2 jobs, full KPIs |
| PM | pm@hsop.com | password | Jordan's 4 leads, 2 jobs (scoped) |
| Contractor | contractor@hsop.com | password | Mike's 2 jobs, docs approved |
| Customer (Sarah) | sarah@example.com | password | Accepted quote, job in progress, invoice |
| Customer (David) | david@example.com | password | Pending quote awaiting approval |

### Login Redirects by Role

- **Owner** → `/dashboard/admin`
- **PM** → `/dashboard/pm`
- **Contractor** → `/dashboard/contractor`
- **Customer** → `/dashboard/customer`

Customers can also **self-register** at `/register`.

### Seed Demo Data

```bash
cd backend
php artisan migrate:fresh --seed
```

## API Endpoints

### Public

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/login` | Login, returns token + user |

### Protected (Bearer token required)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/logout` | Revoke current token |
| GET | `/api/me` | Current authenticated user |
| GET | `/api/dashboard/kpis` | Dashboard KPIs (static zeros) |
| GET/POST | `/api/leads` | Leads resource (stub) |
| GET/POST | `/api/jobs` | Jobs resource (stub) |
| GET/POST | `/api/contractors` | Contractors resource (stub) |
| GET/POST | `/api/customers` | Customers resource (stub) |
| GET/POST | `/api/quotes` | Quotes resource (stub) |
| GET/POST | `/api/invoices` | Invoices resource (stub) |
| GET/POST | `/api/payouts` | Payouts resource (stub) |
| GET | `/api/jobs/{job}/messages` | Job messages (stub) |
| POST | `/api/jobs/{job}/messages` | Create message (stub) |
| GET | `/api/settings` | Settings (stub) |

## Database Tables (13)

`users`, `leads`, `lead_photos`, `jobs`, `contractors`, `customers`, `quotes`, `invoices`, `payments`, `payouts`, `files`, `messages`, `audit_logs`

Plus Laravel system tables: `personal_access_tokens`, `sessions`, `cache`, `migrations`.

## Phase 1 Scope

**Included:**
- Full layout (sidebar + header)
- All module pages/routes as screens
- Role-based auth with Sanctum
- Complete database schema + seed data
- Responsive mobile layout

**Not included (Phase 2+):**
- Lead creation forms with validation
- Quote/invoice generation
- 80/20 pricing calculation
- File/photo uploads
- Real messaging
- Payment/payout processing
- PDF generation
- QuickBooks CSV export
- SMS notifications

## Development

Run both servers in separate terminals:

```bash
# Terminal 1 — API
cd backend && php artisan serve

# Terminal 2 — Frontend
cd frontend && npm run dev
```

## Verify Phase 1

- [ ] `php artisan migrate` completes with 0 errors
- [ ] `php artisan db:seed` runs successfully
- [ ] `POST /api/login` returns a token for all 4 test users
- [ ] React app loads at http://localhost:5173
- [ ] Login works and redirects by role
- [ ] Sidebar + header visible on all protected pages
- [ ] All 12 module routes load without errors
- [ ] App is responsive on mobile
- [ ] No console errors in browser
