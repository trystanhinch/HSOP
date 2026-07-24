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

## Design system (brand tokens)

Visual theme comes from `brands.branding.theme` (see `src/lib/theme.ts`).
`BrandTheme` injects CSS custom properties; components use `var(--color-*)`
and `var(--font-*)` only — never hardcoded hex/fonts.

Acutera defaults = plaster / mud / workshop green + Fraunces/Outfit.
`example-roofing.test` uses a slate roof palette + Libre Baskerville /
Source Sans 3 for the multi-tenant visual check.

Design plan: `../docs/MILESTONE5_PUBLIC_SITE_DESIGN.md`.

See also: `../docs/MILESTONE5_PHASE6_LAUNCH.md`.

New **Node.js** App Platform component (separate from the Vite admin SPA):

| | |
|---|---|
| Root | `public-website` |
| Build | `npm ci && npm run build` |
| Run | `npm run start` |
| Env | `API_URL`, `NEXT_PUBLIC_API_URL` → Laravel API URL |
| Domains | Attach each brand domain; Laravel `brands.domain` + CORS drive tenancy |

Do **not** execute production cutover until Trystan confirms real pricing rates and DNS is ready.
