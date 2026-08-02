# Milestone 6B Phase 5 — Canonical Normalized Learning Record

**Date:** 2026-08-01  
**Scope:** DAT-04/DAT-05 foundational entity — assembled, versioned `learning_records` with field-level provenance; new `properties` + `regions` models.  
**Out of scope:** embeddings, similarity, playbooks, market-source registry, historical import, auto-assembly on job completion, any UI.

---

## Relationship to Phase 4 `learning_normalized_records`

| | Phase 4 `learning_normalized_records` | Phase 5 `learning_records` |
|--|--------------------------------------|----------------------------|
| Purpose | Learning AI **draft workspace** (provisional/pending only) | **Canonical assembled view** of a completed job |
| Writer | Learning AI via gateway tools | System only (`LearningRecordAssemblyService`) |
| Mutability | Append-only evidence drafts | Versioned append on reassembly (`is_current`) |
| Authority | Not source of truth | Not source of truth either — **derived cache** |
| Eligibility | Own draft status (never verified) | **Pointer** to Phase 3 job/estimate eligibility |

**Decision:** Keep Phase 4 table as-is. Build a **separate** `learning_records` table with a clear name so the AI draft path and the system assembly path cannot be confused. Source tables (jobs, leads, estimate_outcomes, quotes, invoices, …) remain authoritative.

---

## What was built

### 1. `regions` (structured geo)
- Hierarchy-ready (`parent_region_id` nullable)
- Seeded exactly **10** appendix regions: Vancouver, Langley, Surrey, Burnaby, Richmond, Coquitlam, New Westminster, North Vancouver, Abbotsford, Chilliwack

### 2. `properties` (normalized property model)
- `raw_address` (preserved) + optional `street`, `city`, `postal_code`, `property_type`, `region_id`
- Nullable `property_id` on `leads` and `jobs` — additive; existing free-text `address` unchanged and still used

### 3. `learning_records` (canonical assembly)
- Versioned per job (`record_group_id`, `version`, `is_current`)
- FK hub: job, lead, property, region, customer, contractor, pm, quote, invoice, current estimate
- `payload` JSON (assembled values) + `provenance` JSON **keyed by field** (`source_table`, `source_id`, `source_timestamp`, `provenance_type`)
- `links` JSON (photo/message/override/performance IDs) + `missing_sources`
- Eligibility: `eligibility_source_type` + `eligibility_source_id` (+ snapshot at assembly). Live status via `resolvedEligibilityStatus()` from Phase 3 source — **not** an independent eligibility field

### 4. Assembly + CLI
- `LearningRecordAssemblyService::assembleForJob($jobId)`
- `php artisan learning:assemble-record {jobId}` — **manual only** (no auto-wire on completion yet)
- `PropertyAddressParser` — best-effort parse; never invents components it cannot derive

### Versioning strategy (Task 5)

**Chosen: versioned append** (new row per reassembly; prior `is_current=false`).

**Why not update-in-place:** Even though this is a derived cache, evaluation/retrieval and Owner audit need to see what the system assembled at each point (e.g. after actuals correction). Overwriting would lose that history. Pattern matches `estimate_outcomes` versioning used elsewhere in 6B. Prior versions remain readable; no data loss on reassembly.

---

## Files touched

| Area | Paths |
|------|--------|
| Migrations | `2026_08_01_000040_create_regions_table.php`, `…000041_create_properties_table.php`, `…000042_create_learning_records_table.php` |
| Models | `Region`, `Property`, `LearningRecord`; `Lead`/`Job` + nullable `property_id` |
| Services | `LearningRecordAssemblyService`, `PropertyAddressParser` |
| Command | `learning:assemble-record` |
| Tests | `tests/Feature/Learning/LearningRecordAssemblyTest.php` |

---

## Test results

**LearningRecordAssemblyTest — 7 passed**  
10 regions · assembly + provenance · reassembly versions · live eligibility from Phase 3 · nullable property/region · artisan command · Phase 4 table distinct.

**Full suite:** **413 passed / 1 pre-existing fail** (`PublicIntakePhase1Test > unknown domain returns 404`).

| Baseline (Phase 4) | This phase |
|--------------------|------------|
| 406 passed | **413** (+7) |

---

## Natural next steps (not built)
- Owner/PM UI to browse assembled learning records + provenance
- Wire `assembleForJob` on job completion (once real-job QA of assembly is done)
- Similarity / embeddings reading from `learning_records.payload`

---

*End of 6B Phase 5 audit.*
