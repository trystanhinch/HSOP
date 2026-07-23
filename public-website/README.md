# ServiceOP public multi-tenant website (Next.js App Router)

SSR site that resolves brand config from the Laravel public API
(`GET /api/public/brand`) using the request hostname. No brand-specific
copy is hardcoded in components.

## Local dev

1. Run Laravel API (`php artisan serve` in `backend/`).
2. Copy `.env.local.example` → `.env.local`.
3. `npm install && npm run dev` (http://localhost:3000).
4. Laravel resolves `localhost` → `PUBLIC_LOCAL_DEFAULT_BRAND_DOMAIN`
   (default `acuteradrywall.ca`). Override with `BRAND_DOMAIN=…`.

## Deploy later (outline only — not built in Phase 2)

New DigitalOcean App Platform **Node.js** component:
- Root directory: `public-website`
- Build: `npm ci && npm run build`
- Run: `npm run start` (or `next start`)
- Env: `API_URL` / `NEXT_PUBLIC_API_URL` → Laravel API URL
- Domains: attach each brand domain (or wildcard) to this component;
  Laravel `brands.domain` + CORS already drive tenancy
- Do **not** merge into the existing Vite admin SPA component
