# Milestone 6B — Phase 0 Current-State & Data-Readiness Audit

**Date:** 2026-07-31 (re-verified after 6A Phase 4 identity hardening)  
**Scope:** Read-only discovery against the ServiceOP codebase (Laravel 12 API + React admin SPA) for **Learning Centre & Advanced AI Estimating**.  
**Purpose:** Establish what Milestone 5 actually captured vs what 6B’s minimum data dictionary still lacks, before any 6B architecture or pricing work.  
**Method:** Schema/models/services inspection + non-destructive local DB counts (`php` bootstrap against the configured app DB).  
**Stack note:** `backend/composer.json` — Laravel 12, Sanctum 4.x, PHP 8.2+. No Scout/Meilisearch/vector/OCR packages. PDF **generation** only via `dompdf`.  
**Related:** `docs/audits/6A_phase0_current_state_audit.md`; 6A Phases 1–4 (review gateway + dedicated `external_review_ai`). Prior draft of this file updated in place for Phase 4 role reality.  
**Explicit non-goals:** architecture proposals, hour/dollar estimates, implementation.

**Local DB snapshot (this audit run):**

| Store | Count / note |
|-------|----------------|
| `estimate_outcomes` | 1 row (id 91); `embedding_vector` = **null**; `environmental_context` = **null** |
| `leads` / with `price_estimate_snapshot` | 23 / 1 |
| `pricing_rules` | 3 (all `is_placeholder = true`) |
| `pricing_override_logs` | 0 |
| `jobs` / with `actual_labour_hours` / `materials_used` | 6 / **0** / **0** |
| `ai_conversation_logs` | 51 |
| `contractor_performance_events` | 0 |
| `review_feedback` / `revision_requests` | 0 / 0 |
| `quotes` / `invoices` | 7 / 3 |
| `lead_photos` | 0 |
| `site_visit_submissions` | 0 |
| `users.role` ENUM | includes `external_review_ai` (6A Phase 4); local count of that role = **0** users yet |
| `ai_super_admin` users | 1 |

Production/staging population may differ. “Populated in practice” below means **this local DB + verified code paths**. Owner should confirm production volumes separately.

---

## 1. M5 Learning Centre Data Capture — Field by Field

Per 6B minimum data dictionary category: **schema exists?** / **populated in practice?** / **missing?**

### 1.1 Identity / lineage

| Field | Status |
|-------|--------|
| Job ID | **Exists** — `jobs.id`; linked from quotes/invoices/outcomes |
| Lead ID | **Exists** — `leads.id`; FK on `estimate_outcomes`, photos, conversations |
| Customer ID | **Exists** — `leads.customer_id`, `jobs.customer_id`, `quotes.customer_id` (User) |
| Property ID | **Missing** — no `properties` table; address is free-text on lead/job |
| Brand ID | **Exists** — `leads.brand_id`, `estimate_outcomes.brand_id`; quote/invoice `brand_name_snapshot` |
| Region | **Partial / missing on job** — marketing `location_pages.region` exists; **no** structured region on lead/job/estimate. Address string only. |
| Source IDs | **Exists** — `company_source_id`, `intake_channel`, `source`, `conversation_id` |

**Reusable as-is:** Lead/job/brand/customer FKs + company source.  
**Requires modification:** Structured property + region for learning filters/comparables.  
**Completely net-new:** Property entity (if 6B requires it as first-class).  
**Complexity:** Medium (property) / Low (pass-through IDs).  
**Owner Input Needed:** Whether free-text address + brand location pages suffice for “region,” or a first-class property/region is required.

---

### 1.2 Project description

| Field | Status |
|-------|--------|
| Customer / internal description | **Exists** — `leads.project_description`, `leads.internal_notes`, `jobs.scope_of_work`, `jobs.internal_notes` |
| Room | **Missing** as dedicated field (may appear only in free text / collected JSON) |
| Dimensions / size | **Partial** — intake `collected_fields.size_sqft` in session/`parse_metadata`; estimator `inputs_used.size_sqft`. Not a first-class lead column. |
| Patch count | **Missing** |
| Photos | **Exists** — `lead_photos`, job update photos, site visit photos, revision photos; timestamps assembled in `JobEstimateSnapshotService` |
| Documents | **Partial** — contractor compliance PDFs; **no** job/estimate document library or invoice PDF *import* |

**Populated in practice (local):** descriptions often present; photos sparse (0 lead photos).  
**Reusable as-is:** Description fields + photo URL/timestamp assembly.  
**Requires modification:** Promote size/room/patch into durable structured fields.  
**Completely net-new:** Patch count; job-scoped historical SOW/PDF attachments.  
**Complexity:** Medium.

---

### 1.3 Service taxonomy

| Field | Status |
|-------|--------|
| Primary service | **Exists** — `service_category` on lead/job/estimate_outcome/pricing_rule |
| Sub-service | **Missing** as distinct field |
| Finish level / texture / insulation / framing / soundproofing | **Missing** as structured fields — category string heuristics only in `PricingRangeEstimator::buildAssumptions` |

**Reusable as-is:** Brand `service_categories` + single category key.  
**Requires modification:** Richer taxonomy.  
**Completely net-new:** Finish/texture/framing/soundproofing attributes.  
**Complexity:** High.  
**Owner Input Needed:** Canonical Acutera (and multi-brand) taxonomy before training labels.

---

### 1.4 Conditions

| Field | Status |
|-------|--------|
| Occupied / vacant | **Missing** |
| Access / height / protection | **Missing** as structured fields |
| Hidden-condition assumptions | **Partial** — free-text `site_visit_submissions.assumptions` / `exclusions` (local submissions: **0**) |

**Reusable as-is:** Site-visit assumptions/exclusions text when used.  
**Completely net-new:** Occupancy/access/height/protection structured capture.  
**Complexity:** Medium.

---

### 1.5 Scope versions

| Version kind | Status |
|--------------|--------|
| Proposed (intake ballpark) | **Partial** — estimate range + description; not a versioned scope document |
| Customer-confirmed | **Partial** — quote accept (`accepted_at`); quote `scope_of_work` + revision chain |
| Approved (internal) | **Partial** — quote statuses; no separate “approved scope” entity |
| Contractor-delivered | **Partial** — job updates/photos; completion flags |
| Change order | **Missing** as entity — closest: `revision_requests`, quote revisions |
| Final verified | **Partial** — `customer_accepted_completion_at`; no verified-scope snapshot |

**Reusable as-is:** Quote revision lineage + completion acceptance.  
**Requires modification:** Map states onto 6B named scope-version vocabulary.  
**Completely net-new:** Change-order entity with cost/hours deltas.  
**Complexity:** High.

---

### 1.6 Estimate inputs

| Field | Status |
|-------|--------|
| Required / known values | **Exists** — `estimate_outcomes.inputs_used` (brand, category, size_sqft, complexity, urgency, flags) |
| Unknowns | **Partial** — null size → widened range / `basis: size_unknown`; no explicit unknowns list |
| Confidence | **Exists** — string on outcome (local sample `"high"`) |

**Reusable as-is:** `inputs_used` + confidence + calculation trail.  
**Requires modification:** Explicit required/unknown inventory.  
**Complexity:** Low–Medium.

---

### 1.7 Labour plan

| Field | Status |
|-------|--------|
| Task hours | **Partial** — placeholder band in `labour_assumptions` (`is_placeholder: true`) |
| Crew size | **Partial schema** — `site_visit_submissions.crew_size` (unused locally) |
| Setup / production / travel | **Missing** on estimates (travel buffer only on availability windows) |

**Reusable as-is:** Placeholder labour JSON shape.  
**Requires modification:** Real labour plans; wire site-visit crew into outcomes.  
**Completely net-new:** Task breakdown, setup, production rates, travel lines.  
**Complexity:** High.

---

### 1.8 Material plan

| Field | Status |
|-------|--------|
| Material / qty / unit | **Partial** — placeholder `materials_assumptions` list |
| Waste factor | **Missing** |
| Cost / supplier | **Missing** |

**Reusable as-is:** Assumption list shape.  
**Completely net-new:** Costed BOM with waste + supplier.  
**Complexity:** High.  
**Note:** Local materials are PLACEHOLDER heuristics, not market rates.

---

### 1.9 Pricing output

| Field | Status |
|-------|--------|
| Low / high | **Exists** — `price_low` / `price_high` |
| Recommended (mid) | **Missing** as stored field |
| Contingency | **Missing** as explicit line (`widened` boolean only) |
| Customer range | **Exists** — low/high + lead snapshot |
| Taxes | **Exists** on quote/invoice (GST); **not** on ballpark outcomes |
| Discounts | **Missing** as first-class model |
| Payout policy | **Exists** — configurable split on settings + job + quote (separate from range estimator) |

**Reusable as-is:** Deterministic range + quote totals + split amounts.  
**Requires modification:** Mid/contingency/discount if 6B requires them on estimates.  
**Complexity:** Medium.

---

### 1.10 Internal economics

| Field | Status |
|-------|--------|
| Contractor target | **Exists** — contractor base/submitted price on lead/quote/job |
| PM / company share | **Exists** — pct + amount columns on quote/job |
| Expected margin | **Partial** — computed in `QuoteResource` / ledger projections; **not** stored on `estimate_outcomes` |

**Reusable as-is:** Quote/job split math.  
**Requires modification:** Persist expected margin onto learning assembly.  
**Complexity:** Low.

---

### 1.11 Comparable jobs

| Capability | Status |
|------------|--------|
| Similarity / retrieval | **Missing** — no “find similar jobs” |
| `EstimateOutcome.embedding_vector` | **Column exists; always null** — `EstimateOutcomeRecorder` hard-sets `null`; comments: reserved, do not populate in M5. Local non-null count = **0**. Still true after 6A work. |
| Text similarity elsewhere | Duplicate detectors use PHP `similar_text` — not job comparables |

**Reusable as-is:** Reserved column + versioned outcomes corpus.  
**Completely net-new:** Embedding generation, index, comparable retrieval.  
**Complexity:** High.

---

### 1.12 Estimate versioning

| Field | Status |
|-------|--------|
| Model / provider | **Exists** — `ai_provider`, `ai_model`, `ai_model_version` (intake AI context); **math engine** is `pricing_range_v1` |
| Instructions / retrieval index / policy | **Missing** as versioned artifacts |
| Timestamp / actor | **Exists** — `estimated_at`, `actor_id`, `source_kind`, `supersedes_id`, `reason`, `estimate_group_id` + `version` |

**Reusable as-is:** Append-only `EstimateOutcomeRecorder`.  
**Completely net-new:** Instruction/policy/retrieval-index versioning for Learning AI.  
**Complexity:** Medium.

---

### 1.13 Overrides

| Field | Status |
|-------|--------|
| Prior / new value | **Exists** — `pricing_override_logs.before_json` / `after_json` |
| Reason / user / date | **Exists** — `reason`, `actor_id`, timestamps; optional `estimate_outcome_id` |
| Kinds | `rule_edit`, `estimate_manual_adjust` |

**Populated in practice (local):** **0** rows (paths covered by tests).  
**Reusable as-is:** Override log + estimate versioning on manual adjust.  
**Complexity:** Low.

---

### 1.14 Actual execution

| Field | Status |
|-------|--------|
| Actual labour hours | **Schema + write path exist** — `jobs.actual_labour_hours`; Job complete API + `JobDetail.jsx`. Local: **0/6** |
| Actual materials | **Same** — `jobs.materials_used`. Local: **0** |
| Equipment / disposal | **Missing** |
| Contractor cost | **Partial** — submitted/base price; not full actual cost ledger |
| Duration | **Partial** — schedule dates; no precise hours-worked calendar |
| Delays | **Missing** structured |
| Change orders / rework | **Partial** — `revision_requests` + performance events `revision_requested` / `callback` alias |

UI still labels completion capture as “Learning Centre data (Milestone 6)” in places, but M5 already shipped columns + API. Operational population is the gap.

**Reusable as-is:** Job actuals fields + performance event hooks.  
**Requires modification:** Enforce capture for learning-eligible jobs; richer cost lines.  
**Completely net-new:** Equipment, disposal, delay taxonomy.  
**Complexity:** Medium–High.

---

### 1.15 Financial outcome

| Field | Status |
|-------|--------|
| Accepted price | **Exists** — accepted quote totals |
| Invoice / payment / refund | **Exists** — invoices, Stripe fields, refund paths, `financial_ledger_entries` |
| Tax / discount | Tax yes (GST); discount **no** first-class |
| Net revenue / gross profit / margin | **Partial** — derivable; not a dedicated learning outcome rollup row |

**Reusable as-is:** Quotes, invoices, payouts, ledger.  
**Requires modification:** Stable profit/margin snapshot on learning assembly.  
**Complexity:** Medium.

---

### 1.16 Quality and feedback

| Field | Status |
|-------|--------|
| Completion status | **Exists** — job status + `completed_at` / customer acceptance |
| Customer feedback / review | **Exists** — `review_feedback`; local **0** rows |
| Complaint / callback / warranty | **Partial** — callback aliased from revision request; **no** warranty entity |

**Reusable as-is:** Review feedback + revision/callback events.  
**Completely net-new:** Warranty tracking.  
**Complexity:** Medium.

---

### 1.17 Learning eligibility

| Field | Status |
|-------|--------|
| Verified / Provisional / Excluded / Pending | **Missing** — no learning-eligibility status on job or estimate_outcome |
| Closest proxies | `is_placeholder` on rules/outcomes; `is_test_data`; completion + customer acceptance |

**Completely net-new:** Eligibility state machine + admin tooling.  
**Complexity:** Medium.  
**Owner Input Needed:** Who may mark Verified vs Excluded; whether placeholder estimates are auto-Excluded.

---

### Section 1 complexity summary

**Overall data dictionary readiness: High** — M5 delivered a solid **capture foundation** (outcomes, overrides schema, conversations, snapshot assembly, actuals columns) but large dictionary areas are missing or placeholder-only, and local population of actuals/overrides/performance/feedback is near-zero.

---

## 2. Existing Pricing / Estimate Engine

### What exists today

**A. Customer-facing ballpark range**  
- `App\Services\Pricing\PricingRangeEstimator` — deterministic `pricing_range_v1`.  
- Matches active `PricingRule` by brand + `service_category` (`flat` / `tiered` / `per_sqft`).  
- Outputs: `low`, `high`, confidence, calculation steps, placeholder materials/labour assumptions.  
- Wired from public intake via `EstimateOutcomeRecorder`.

**B. Quote / payout math**  
- `App\Services\PricingService` — customer subtotal from contractor price ÷ contractor split %, then GST; PM/company amounts from pct of subtotal.

**C. AI’s role today**  
- Intake LLM extracts **fields** (`service_category`, `size_sqft`, `complexity`, …).  
- Those fields feed `PricingRangeEstimator`.  
- **Customer-facing numbers are not produced by the LLM.** Engine string remains `pricing_range_v1`; `ai_provider`/`ai_model` on outcomes record intake context, not LLM price math.  
- Manual override sets human low/high without LLM math.

### Split 80/10/10

| Layer | Implementation |
|-------|----------------|
| Defaults | Settings `split_contractor_pct` / `split_pm_pct` / `split_company_pct` default **80 / 10 / 10** |
| Per job | `jobs.split_*` seeded from settings; updatable |
| Per quote | pct + amount columns |
| Preview | `/api/settings/pricing-preview` |

**Configurable, not hardcoded** — hardcoded values appear only as fallbacks when settings/job pct missing.

### What is reusable as-is
- Full deterministic range estimator + PricingService split math.  
- Clear separation today between AI field extraction and numeric pricing (aligns with 6B “strict separation” for **numbers**).

### What requires modification
- If 6B adds Learning AI suggestions, keep customer-facing commit on deterministic or human-approved engines.  
- Replace placeholder rates for production learning.

### What is completely net-new
- Advanced AI estimating product (labour/BOM/price suggestions with human/deterministic commit).  
- Mid/recommended/contingency lines if required.

### Complexity
**Medium** to extend dual-engine layout; **High** for full advanced estimating product.

### Owner Input Needed
- Confirm whether AI→fields→deterministic-range is acceptable as the baseline “AI does not set price” policy, or whether field influence itself needs firewalling for 6B demos.

---

## 3. Historical Data Import Capability

### What exists today
- **Upload storage:** `UploadStorage` + S3/Spaces for images and contractor documents (PDF/JPG/PNG).  
- **PDF generation (outbound):** `dompdf` for invoice PDFs — not ingestion.  
- **Voice transcription:** Whisper / Realtime for intake voice notes — not document OCR.  
- **Email intake parsing:** Gmail/text parsers for lead fields — not historical job packs.  
- **No** spreadsheet import library; **no** OCR/document-extraction service.  
- M5 walkthrough lists **estimate-import** as deferred.

### What is reusable as-is
- Generic file upload + validation patterns; media URL storage.

### What requires modification
- Extend upload pipeline with job/import batch entity and type rules for spreadsheets/PDFs.

### What is completely net-new
- Historical job import (CSV/XLSX/PDF), OCR/extraction, mapping UI, eligibility tagging of imports.

### Complexity
**High**.

### Owner Input Needed
- Volume/format of historical jobs (spreadsheet vs scanned invoices) — drives OCR necessity.

---

## 4. Similarity / Retrieval Infrastructure

### What exists today
- `estimate_outcomes.embedding_vector` — **reserved, always null** (recorder + migration comments; local non-null = **0**). Confirms 6A Phase 0 note: **still unused**.  
- `environmental_context` — always null (weather deferred by Owner).  
- **composer.json / package.json:** no Laravel Scout, Meilisearch, Pinecone, pgvector, or embedding SDK beyond OpenAI HTTP for chat/transcription.  
- “Similar” code: `similar_text` for duplicate leads/customers/content only.  
- Learning snapshot exposes `embedding_vector` but documents it as always null in M5.

### What is reusable as-is
- Reserved column + versioned corpus; OpenAI HTTP plumbing (embeddings API **not** called today).

### What requires modification
- Populate embeddings; choose storage (JSON column vs external index).

### What is completely net-new
- Vector/index stack, indexing pipeline, “similar jobs” API/UI, retrieval policy versioning.

### Complexity
**High**.

---

## 5. Role / Permission Readiness for 6B

### What exists today

**Human / ops roles (ENUM):**  
`owner`, `pm`, `contractor`, `customer`, `ai_super_admin`, `content_editor`, **`external_review_ai`** (added 6A Phase 4).

| Role | Current purpose |
|------|-----------------|
| `owner` / `pm` / `contractor` / `customer` | Operational product roles |
| `content_editor` | Brand content only |
| `ai_super_admin` | Operational AI actor (Command Center / AiActionGate); interactive login blocked |
| `external_review_ai` | Dedicated External Review AI; review-gateway only; login blocked; never inherits `ai_super_admin` |

**Learning Centre today:** snapshot GETs are staff-authenticated; estimate overrides / pricing rules are owner-style; actuals write via job completion. **No** Learning AI role or `learning:*` abilities.

**6A review-gateway patterns (Phases 1–4) — built and available as a template:**
- Dedicated service-identity role (`external_review_ai`)  
- Sanctum abilities (`review:read`, `review:code-read`, `review:evidence-write`) with **explicit** ability check (rejects `*`)  
- Role + ability defense in depth  
- Append-only access ledger (`review_gateway_access_logs`)  
- Independent kill switch (`review_gateway_kill_switch`)  
- Token TTL / `expires_at`, nearing-expiry surfacing, Owner Review Center (list/revoke/kill switch)  
- Artisan issue + legacy migrate commands  

### Overlap with 6B Learning AI vs differences

| Pattern | 6A External Review AI | 6B Learning AI (needed) |
|---------|----------------------|-------------------------|
| Dedicated role (not `ai_super_admin`) | Yes — `external_review_ai` | Likely yes — **net-new** role/abilities (e.g. `learning_ai`); do **not** reuse Review AI principal |
| Sanctum abilities + explicit check | `review:*` | New namespace e.g. `learning:*` |
| Append-only access ledger | Yes | Reusable pattern; separate table/key |
| Kill switch | `review_gateway_kill_switch` | Separate learning kill switch |
| Token TTL / revoke / Owner UI | Yes | Reusable pattern |
| **Read** tools | Primary (GET gateway) | Needed for corpus read |
| **Write** tools | Essentially none in production routes (GET-only gateway; `review:evidence-write` defined but unused for writes) | **Required** — scoped writes to estimate/evidence/evaluation/eligibility/embedding records — **not a direct copy** of 6A |
| Separation from ops AI | Enforced vs `ai_super_admin` | Must also stay separate from `external_review_ai` and human PM write paths |

### Mapping gaps for 6B

| 6B need | Current fit |
|---------|-------------|
| Owner governance | Strong |
| PM operational capture | Strong |
| Contractor actuals | Partial |
| Customer feedback | Exists |
| Learning AI read corpus | Gap — no `learning:read` |
| Learning AI scoped write | **Gap** — no write-scoped Learning identity; 6A does not supply this |
| Reuse Review AI role for Learning | **Must not** — Owner decision for Review AI was dedicated + non-inheriting; Learning needs its own identity |

### What is reusable as-is
- 6A **patterns** (not the Review AI role itself): abilities, middleware style, ledger, kill switch, TTL, Owner admin UI.  
- `EstimateOutcomeRecorder` append-only versioning; `PricingOverrideLog` for human overrides.

### What requires modification
- New Learning ability namespace + dedicated principal (parallel to Phase 4, not shared with Review AI).  
- Write-capable tool surface with allowlisted mutation types (eligibility, embeddings, evaluation records) — stricter than 6A’s read-only gateway.

### What is completely net-new
- Learning eligibility admin matrix; Learning AI credentials; write ACL for estimate/evidence/evaluation.

### Complexity
**Medium–High** (patterns exist; write scope is new product risk).

### Owner Input Needed
- Dedicated `learning_ai` (or similar) role vs ability-only tokens — **do not** attach Learning write to `external_review_ai` or `ai_super_admin` without explicit Owner decision.  
- Whether Learning AI may write eligibility/embeddings directly or must be suggestion-only with human commit.

---

## 6. Market Source / Rate Data

### What exists today
- **`pricing_rules`** — per-brand, per-`service_category` rate sheet: `rule_type`, `base_rate`, `size_tiers`, `complexity_modifiers`, min/max, `currency`, `status`, **`is_placeholder`**, `notes`.  
- Admin CRUD + preview (`PricingRuleController`).  
- Local: **3 rules, all placeholders**.  
- Material/labour unit-cost tables: **none**.  
- Split/GST settings = commercial policy, not market labour/material rates.

### What is reusable as-is
- Pricing rule admin model as customer-range rate sheet; override logging on rule edits.

### What requires modification
- Replace placeholder rules with real rates; optionally extend for labour/material unit costs.

### What is completely net-new
- Supplier catalogs, regional labour rates, waste/contingency tables.

### Complexity
**Medium** (config data) to **High** (full market BOM).

### Owner Input Needed
- Source of truth for live rates; timeline to retire `is_placeholder`.

---

## Summary Table

| 6B Component | M5 Data Ready? | Reuse % | New Build Required | Complexity | Key Risk |
|--------------|----------------|---------|--------------------|------------|----------|
| Identity / lineage | Partial | ~70% | Property/region structure | Medium | Region filters without structured geo |
| Project description & media | Partial | ~55% | Room/patch; durable size; docs | Medium | Sparse photos; size only in JSON |
| Service taxonomy | Low | ~25% | Sub-service + finish attributes | High | Training labels won’t match reality |
| Conditions | Low | ~15% | Occupancy/access/height fields | Medium | Site-visit text unused locally |
| Scope versioning | Partial | ~40% | Change orders; named scope gates | High | Quote revisions ≠ full scope ledger |
| Estimate inputs & confidence | Mostly | ~75% | Explicit unknowns inventory | Low–Med | Coarse confidence strings |
| Labour / material plans | Placeholder only | ~30% | Real BOM + crew/travel/waste/cost | High | Learning on placeholder heuristics |
| Pricing output & split | Strong for range+split | ~80% | Mid/contingency/discounts if required | Medium | AI field influence ≠ AI pricing |
| Internal economics | Strong on quotes | ~70% | Persist margin on learning rows | Low | Snapshot omits derived profit |
| Comparables / embeddings | Not ready | ~10% | Embeddings + retrieval stack | High | Unused `embedding_vector` looks “ready” |
| Estimate versioning meta | Strong core | ~65% | Policy/instruction/index versions | Medium | AI model fields ≠ price engine |
| Overrides | Schema ready | ~85% | Operational adoption | Low | Zero local override history |
| Actuals & execution | Schema ready; data empty | ~45% | Enforce capture; equipment/delays/CO | High | Local 0 actuals; UI still frames as M6 |
| Financial outcome | Strong operational | ~70% | Learning rollup fields | Medium | Profit not first-class on outcomes |
| Quality / feedback | Schema ready | ~60% | Warranty; populate reviews | Medium | No local feedback/events |
| Learning eligibility | Missing | ~5% | Status model + governance | Medium | Train on test/placeholder jobs |
| Deterministic pricing engine | Ready | ~90% | Advanced estimator product | Medium | Placeholder rates |
| Historical import / OCR | Missing | ~10% | Import + OCR pipeline | High | No extraction service |
| Vector / similarity infra | Column only | ~5% | Full retrieval infra | High | Unused embedding column |
| Roles for Learning AI | Partial (6A patterns; Phase 4 dedicated Review AI is **not** Learning AI) | ~45% | Learning identity + **scoped write** ACL | Medium–High | Copying read-only Review AI under-serves write needs |
| Market rate sheets | Placeholder rules | ~40% | Real rates + optional BOM rates | Medium–High | All local rules placeholder |

*Reuse % is qualitative share of capability already present — not an effort estimate.*

---

## M5 Gap Report

Evidence list of fields/capabilities **6B needs that M5 did not actually deliver** (or delivered only as empty placeholders / unused columns). Per DAT-01/DAT-02/DAT-03 intent: keep these as **Milestone 5 obligations / data readiness** rather than silently absorbing into chargeable 6B scope — **Owner confirmation required** on which items remain M5 vs move into 6B.

### A. Schema promised / reserved but unused
1. **`estimate_outcomes.embedding_vector`** — always null; no population path (still true).  
2. **`estimate_outcomes.environmental_context`** — always null; weather deferred.  
3. **Comparable-job / similarity mechanism** — not built (column ≠ feature).

### B. Capture columns exist but not operationally populated (local evidence)
4. **`jobs.actual_labour_hours` / `jobs.materials_used`** — API + UI exist; **0/6** jobs populated locally.  
5. **`pricing_override_logs`** — wired; **0** rows locally.  
6. **`contractor_performance_events`** — recorder exists; **0** rows locally.  
7. **`review_feedback`** — model exists; **0** rows locally.  
8. **Site visit structured fields** (`crew_size`, `measurements`, assumptions) — schema; **unpopulated** locally.  
9. **Lead/job photos at scale** — tables exist; sparse local media.

### C. Dictionary fields never modeled in M5
10. **Property entity** (property ID).  
11. **Structured region** on lead/job/estimate.  
12. **Room, patch count**, finish level, texture, framing, soundproofing (structured).  
13. **Occupied/vacant, access, height, protection** conditions.  
14. **Explicit scope version types** including **change orders** and final verified scope snapshots.  
15. **Recommended/mid price, contingency line, discounts** on estimates.  
16. **Labour plan detail:** setup, production, travel (estimate-scoped); real non-placeholder task hours.  
17. **Material plan detail:** waste factor, unit cost, supplier.  
18. **Equipment, disposal, delay** actuals.  
19. **Warranty** tracking.  
20. **Learning eligibility** status (Verified / Provisional / Excluded / Pending).  
21. **Estimate policy / instruction / retrieval-index versioning**.  
22. **Historical job import** (PDF/spreadsheet) and **OCR/extraction**.  
23. **Vector DB / Scout / Meilisearch** (or any embedding index).  
24. **Learning AI write-scoped identity/abilities** (distinct from humans, from `ai_super_admin`, and from 6A `external_review_ai`).

### D. Delivered only as placeholders (not production learning data)
25. **`pricing_rules` all `is_placeholder`** (local).  
26. **`materials_assumptions` / `labour_assumptions`** marked placeholder — unsuitable as verified training labels without eligibility gating.

### E. Clarifications (not pure gaps — avoid double-counting)
- **Deterministic vs AI pricing:** Customer-facing range and quote math are server-side deterministic; AI influences **inputs**, not arithmetic.  
- **80/10/10:** Configurable via settings/job/quote — **not** a gap.  
- **Estimate versioning core** (group/version/supersedes/actor/engine): **delivered** in M5.  
- **Financial ops** (invoice/payment/refund/ledger): **delivered** operationally; learning rollup packaging incomplete.  
- **6A Phase 4** delivered Review AI identity patterns reusable as a *template*; it did **not** deliver Learning AI write access (and must not be conflated with it).

---

## Owner Input Needed (consolidated)

1. Confirm production counts for outcomes, actuals, overrides, photos, reviews (local ≠ prod).  
2. Property/region: free-text sufficient or first-class entities?  
3. Canonical service taxonomy + finish attributes for Acutera.  
4. Which M5 Gap Report items remain M5 debt vs explicit 6B scope (DAT-01/02/03).  
5. Learning AI identity: new dedicated role (recommended parallel to `external_review_ai`) vs reuse — **Owner must decide**; do not silently use Review AI or ops AI.  
6. Learning AI write policy: direct writes vs suggestion + human commit.  
7. Historical import formats/volumes (OCR necessity).  
8. Real rate-sheet source and timeline to retire `is_placeholder`.  
9. Whether AI-extracted fields feeding the deterministic range violates the intended 6B firewall.

---

*End of Phase 0 audit. No application code, migrations, or config were changed for this document (temporary local stats script removed after use).*
