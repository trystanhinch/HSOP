# Milestone 5 Phase 6 — Launch Checklist & Deploy Plan

## Acutera pre-launch checklist

### Pricing & product config
- [ ] Real pricing rates loaded for Acutera (`pricing_rules.is_placeholder = false`) — **blocked on Trystan**
- [ ] Service categories / keywords match what visitors say on the site
- [ ] Estimate disclaimer copy reviewed (placeholder vs live rates)

### Domains & SSL
- [ ] DNS for `acuteradrywall.ca` (+ `www`) pointed at the **new Next.js** App Platform component (not admin SPA)
- [ ] Domain(s) attached on DO App Platform for the public-website component
- [ ] SSL/HTTPS certificates active and forced
- [ ] `brands.domain` row matches the live hostname exactly

### Production environment (Laravel API — existing component)
- [ ] `OPENAI_API_KEY` set; `AI_CONVERSATIONAL_PROVIDER=openai`
- [ ] DB connection (production), migrations through `2026_07_24_00000*` applied
- [ ] Twilio (SMS) credentials verified
- [ ] Resend / mail credentials verified
- [ ] DigitalOcean Spaces (media uploads) verified
- [ ] Stripe keys if card/deposit flows are enabled
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `CORS_ALLOWED_ORIGINS` = admin SPA only; brand domains auto-merge from DB
- [ ] `PUBLIC_EXTRA_CORS_ORIGINS` empty or minimal in production (no open localhost if unused)
- [ ] `PUBLIC_LOCAL_DEFAULT_BRAND_DOMAIN` irrelevant in prod (Host-based resolution)

### Availability & matching
- [ ] Availability windows configured for real PM/contractor pool (timezone America/Vancouver)
- [ ] At least one approved contractor in Acutera `CompanySource.default_contractor_ids`
- [ ] Contractor has matching service category eligibility
- [ ] Scheduler running: `booking:release-expired-holds` (every minute)

### Monitoring / ops
- [ ] App Platform logs + Laravel `storage/logs` / DO log drain watched first 48h
- [ ] Alert on 5xx spike for `/api/public/*` and Next.js routes
- [ ] OpenAI usage/rate-limit dashboard or log grep for `mock_openai_rate_limited`
- [ ] Confirm AI kill switch path (`ai_kill_switch` setting) known to ops

### Rollback plan
1. Point DNS back to previous marketing/static host **or** roll DO component to prior revision
2. Optionally set `brands.status` inactive to stop public intake (API returns brand_not_found)
3. Keep Laravel API running — admin SPA / Milestone 4 flows unaffected
4. Holds expire automatically; no manual slot cleanup required beyond monitoring `slot_claims`

---

## Deploy plan: Next.js public site (DigitalOcean App Platform)

Do **not** merge into the existing Vite admin SPA component. Do **not** execute until pricing + DNS are ready.

### New App Platform component
| Setting | Value |
|---|---|
| Type | Web Service / Node.js |
| Source | same repo, root directory `public-website` |
| Build command | `npm ci && npm run build` |
| Run command | `npm run start` (`next start`) |
| HTTP port | `3000` (or DO default) |
| Health check | `/` or `/quote` |
| Instance size | start small (basic-xxs / equivalent); scale if OpenAI latency queues |

### Environment variables (Next component)
| Var | Purpose |
|---|---|
| `API_URL` | Laravel API origin for SSR brand fetch, e.g. `https://api.…` |
| `NEXT_PUBLIC_API_URL` | Same origin for browser `/api/public/*` calls |
| `NODE_ENV` | `production` |

Optional: do **not** set `BRAND_DOMAIN` in production — hostname must drive tenancy.

### Domains
1. Attach `acuteradrywall.ca` + `www` to this component
2. Align `brands.domain` with the apex hostname used in Host headers
3. Enable managed SSL
4. DNS A/CNAME → App Platform target for this component only

### Laravel API cutover notes
- Migrations already include availability, bookings, estimate_outcomes, contractor match fields
- After real rates: update pricing rules, clear any cached CORS key if brands change (`cors.active_brand_origins`)
- Smoke test: `/api/public/brand` with production Host → Acutera config; full chat → estimate → hold → submit

### Rollback
See checklist above. Prefer DNS or DO revision rollback over hotfixing under live traffic.

---

## Phase 6 verification summary (local)

| Area | Result |
|---|---|
| Rate limits | Triggered (429) on `public-intake-start`; dedicated availability/hold limiters |
| Response leaks | `parse_metadata` / estimate internals / AI `usage` stripped from public responses |
| Uploads | Non-image rejected 422; size/type enforced server-side; API always JSON on errors |
| CORS | Allowlist = admin + brand domains + extras; patterns empty; evil Origin gets no ACAO |
| Prompt injection | System + tool instructions harden against role override / cross-brand exfil; category keys brand-scoped |
| Booking load | 10 sequential HTTP holds → 1 created / 9×409; 12× DB burst → 1 claim; different slots all succeed |
| OpenAI 429 | Falls back to `mock_openai_rate_limited` with visitor-safe message |
| Empty slots | API `count:0`; ChatWidget explains submit-without-slot |
| No contractor | Booking still confirmed publicly; PM NextAction internally |
| Multi-tenant E2E | Second brand full flow isolated (brand, category, slots, lead) |
