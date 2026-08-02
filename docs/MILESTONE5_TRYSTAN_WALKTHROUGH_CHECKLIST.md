# Trystan live walkthrough — demo checklist

Local URLs (standard ports):
- Public site: http://localhost:3000
- Admin app: http://localhost:5173
- API: http://127.0.0.1:8000

Accounts (from DemoSeeder):
- Owner: admin@hsop.com / password
- PM: pm@hsop.com / password
- Contractor: contractor@hsop.com / password
- Customer: sarah@example.com / password

Default public brand on localhost = Acutera. Second brand = set `BRAND_DOMAIN=example-roofing.test` in `public-website/.env.local` and restart `npm run dev` (or pass Host/X-Brand-Domain against the API).

---

## 1. Acutera customer journey (~8–10 min)

1. Open http://localhost:3000 — confirm Finish Reveal hero, Acutera branding (plaster/green, Fraunces).
2. Click **Drywall & Paint** service page — template + CTA.
3. **Get a quote** / Start a quote → chat starts (not stuck on “Starting chat…”).
4. Multi-turn chat: describe water damage / ceiling stain in Surrey → name → phone → email → size/complexity.
5. **Add photos** — upload a real phone/desktop photo.
6. Confirm **Your finish range** estimate card appears.
7. Pick a site-visit slot (times should look like Pacific business hours, not weird AM/PM).
8. **Submit request** — confirmation with lead #.
9. Note the lead id for admin.

## 2. Admin — resulting lead (~3–4 min)

1. http://localhost:5173/login as admin@hsop.com
2. **Leads** → open the new lead.
3. Point out: conversation / raw chat, estimate low–high, photo(s), booking + site visit, assigned contractor (Mike), `website_chat` / brand Acutera.

## 3. Contractor notification (~1 min)

1. Still as admin: SMS logs for that lead, or filter `site_visit_contractor_assigned`.
2. Expected: SMS to Mike Contractor with visit date/time and dashboard link.

## 4. Second brand — Example Roofing (~5–6 min)

1. In `public-website/.env.local` uncomment/set:
   `BRAND_DOMAIN=example-roofing.test`
   `NEXT_PUBLIC_BRAND_DOMAIN=example-roofing.test`
2. Restart public-website `npm run dev` on :3000.
3. Hard-refresh http://localhost:3000 — slate theme, “Stop the leak before winter.”, Libre Baskerville.
4. Short roofing chat → estimate (higher $/sqft than Acutera) → slot (Mon–Fri 10–14 Pacific) → submit.
5. Admin: new roofing lead, brand Example Roofing, different pricing/availability — proves multi-tenant with config only.
6. After demo: clear BRAND_DOMAIN lines and restart Next so localhost defaults back to Acutera.

## 5. Optional extras if time (~2–3 min)

- Accounting dashboard
- AI Command Center (existing chats)
- Reviews list
- Settings → AI Action Log (`public_intake` rows)

Not the focus — skip if the customer journey already sold it.

---

## Suggested order

Do **not** reorder 1→2→3→4. Showing admin + SMS right after Acutera submit makes the end-to-end story land before the second-brand proof.
