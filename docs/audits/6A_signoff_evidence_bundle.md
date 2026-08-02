# Milestone 6A.1 — Sign-off Evidence Bundle

**Date captured:** 2026-07-31 (local environment: MySQL `hsop_job_command`)  
**Scope:** Implementation evidence for External Review AI gateway (Phases 1–6).  
**Method:** Commands run against this codebase/database; outputs below are verbatim unless noted as redacted (IP omitted).

---

## 1. PERMISSION MATRIX FOR `external_review_ai`

### Ability → grants / does not grant

| Sanctum ability | Grants access to | Does **not** grant |
|-----------------|------------------|--------------------|
| `review:read` | `GET /api/review-gateway/tools/lead-journey/{leadId}`, `GET …/tools/search`, `GET …/tools/ai-conversation-log/{conversationId}` (middleware `review.ai`, default required ability) | Production mutations (jobs/leads/quotes/payments); Command Center / AiActionGate routes; learning-gateway routes; source-code tools; evaluation write tools |
| `review:code-read` | `GET /api/review-gateway/tools/source-file`, `GET …/tools/source-search` (middleware `review.ai:review:code-read`) | Data-read tools alone without also holding `review:read` where those routes require it; any write; Command Center; learning-gateway; evaluation writes |
| `review:evidence-write` | `POST /api/review-gateway/tools/evaluation-run`, `POST …/tools/evaluation-finding` only | Read tools if ability missing; Command Center; learning-gateway; any other write under `/api/review-gateway/*`; owner admin Review Center APIs |

**Config abilities (actual):**
```
abilities=["review:read","review:code-read","review:evidence-write"]
actor_role=external_review_ai
actor_email=external-review-ai@serviceop.system
```

**Explicit non-grants (enforced by role middleware elsewhere, proven in Phase 4 tests):**
- Command Center: `/api/command-center/*` requires `role:owner,pm` — not `external_review_ai`
- AiActionGate: `/api/ai/actions/*` not opened to `external_review_ai`
- Learning gateway: `/api/learning-gateway/*` uses `learning.ai` principal, not review abilities

### Route list — `/api/review-gateway/*` and `/api/admin/review-gateway/*`

**Attempted command (FAILED in this environment):**
```text
$ php artisan route:list --path=review-gateway

   ReflectionException 

  Class "PmMeetingController" does not exist

  at vendor\laravel\framework\src\Illuminate\Foundation\Console\RouteListCommand.php:255
```

**Equivalent capture (worked):** Laravel `Route` facade dump of every URI containing `review-gateway` (method, path, middleware, action):

```text
GET	/api/review-gateway/tools/lead-journey/{leadId}	api, auth:sanctum, active.user, review.gateway.log, review.ai	App\Http\Controllers\Api\ReviewGatewayController@leadJourney
GET	/api/review-gateway/tools/search	api, auth:sanctum, active.user, review.gateway.log, review.ai	App\Http\Controllers\Api\ReviewGatewayController@search
GET	/api/review-gateway/tools/ai-conversation-log/{conversationId}	api, auth:sanctum, active.user, review.gateway.log, review.ai	App\Http\Controllers\Api\ReviewGatewayController@aiConversationLog
GET	/api/review-gateway/tools/source-file	api, auth:sanctum, active.user, review.gateway.log, review.ai:review:code-read	App\Http\Controllers\Api\ReviewGatewayController@sourceFile
GET	/api/review-gateway/tools/source-search	api, auth:sanctum, active.user, review.gateway.log, review.ai:review:code-read	App\Http\Controllers\Api\ReviewGatewayController@sourceSearch
POST	/api/review-gateway/tools/evaluation-run	api, auth:sanctum, active.user, review.gateway.log, review.ai:review:evidence-write	App\Http\Controllers\Api\ReviewGatewayController@evaluationRun
POST	/api/review-gateway/tools/evaluation-finding	api, auth:sanctum, active.user, review.gateway.log, review.ai:review:evidence-write	App\Http\Controllers\Api\ReviewGatewayController@evaluationFinding
GET	/api/admin/review-gateway/summary	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@summary
GET	/api/admin/review-gateway/access-logs	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@accessLogs
GET	/api/admin/review-gateway/tokens	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@tokens
POST	/api/admin/review-gateway/tokens/{id}/revoke	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@revokeToken
PATCH	/api/admin/review-gateway/kill-switch	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@updateKillSwitch
GET	/api/admin/review-gateway/evaluation-runs	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@evaluationRuns
GET	/api/admin/review-gateway/evaluation-runs/{id}/findings	api, auth:sanctum, active.user, restrict.content_editor, role:owner	App\Http\Controllers\Api\AdminReviewGatewayController@evaluationFindings
```

Owner admin control plane is `role:owner` only (never `review:*` token abilities).

---

## 2. SEPARATE ROLE AND IDENTITY — SCREENSHOT-EQUIVALENT OUTPUT

### `users` column listing (actual)
```text
Array
(
    [0] => id
    [1] => name
    [2] => email
    [3] => phone
    [4] => password
    [5] => role
    [6] => brand_id
    [7] => status
    [8] => last_login_at
    [9] => invited_at
    [10] => invitation_status
    [11] => suspended_at
    [12] => is_developer
    [13] => sms_enabled
    [14] => stripe_account_id
    [15] => stripe_onboarding_status
    [16] => stripe_requirements_due
    [17] => stripe_payout_ready
    [18] => remember_token
    [19] => created_at
    [20] => updated_at
    [21] => is_test_data
)
```

### `users.role` ENUM (actual `SHOW COLUMNS`)
```json
{
    "Field": "role",
    "Type": "enum('owner','pm','contractor','customer','ai_super_admin','content_editor','external_review_ai','learning_ai')",
    "Null": "NO",
    "Key": "",
    "Default": "customer",
    "Extra": ""
}
```

`external_review_ai` and `ai_super_admin` are **distinct** ENUM members.

### Users present (non-sensitive; no password/token secrets)

**`ai_super_admin` (pre-existing):**
```json
{
    "id": 7,
    "name": "AI Super Admin",
    "email": "ai-super-admin@serviceop.system",
    "role": "ai_super_admin",
    "status": "active",
    "last_login_at": null,
    "created_at": "2026-07-08 22:46:42"
}
```

**`external_review_ai` (created during this evidence capture via `review-ai:issue-token`):**
```json
{
    "id": 29696,
    "name": "External Review AI",
    "email": "external-review-ai@serviceop.system",
    "role": "external_review_ai",
    "status": "active",
    "last_login_at": null,
    "created_at": "2026-07-31 21:35:51"
}
```

**Interactive login blocked:** `AuthController::login` returns 403 for roles `ai_super_admin`, `external_review_ai`, `learning_ai` with message `This account cannot be used for interactive login.` Proven by test `ReviewGatewayIdentityHardeningTest::test_external_review_ai_interactive_login_blocked` (PASS in suite run below). `last_login_at` remains null on both service users above.

---

## 3. AUDIT LOG — SUCCESSFUL ISOLATION + DENIED CROSS-ACCESS

### PHPUnit isolation suite (actual)
```text
$ php artisan test --filter=ReviewGatewayIdentityHardening

   PASS  Tests\Feature\ReviewGateway\ReviewGatewayIdentityHardeningTest
  ✓ external review ai can access all phase1 and phase2 tools                                                    2.68s  
  ✓ ai super admin with review abilities cannot access review gateway                                            0.23s  
  ✓ external review ai cannot access command center or ai action gate                                            0.33s  
  ✓ expired review token is rejected                                                                             0.15s  
  ✓ issue token command attaches to external review ai with expiry                                               0.14s  
  ✓ migrate legacy tokens lists without revoke flag                                                              0.16s  
  ✓ migrate legacy tokens revokes with flag                                                                      0.14s  
  ✓ external review ai interactive login blocked                                                                 0.20s  
  ✓ summary active tokens exclude ai super admin legacy                                                          0.25s  

  Tests:    9 passed (34 assertions)
```

**(a) Successful access** — `test_external_review_ai_can_access_all_phase1_and_phase2_tools` asserts `GET /api/review-gateway/tools/search` → 200 / `tool=search`, plus source-file/source-search OK.

**(b) Denied `ai_super_admin` with crafted `review:*` abilities** — `test_ai_super_admin_with_review_abilities_cannot_access_review_gateway` asserts 403 / `code=review_role_required` on search and source-file.

Re-run of those two methods alone also PASS:
```text
✓ ai super admin with review abilities cannot access review gateway
✓ external review ai can access all phase1 and phase2 tools
```

### Live HTTP calls against this DB (outside PHPUnit transactions)

**Denied forged `ai_super_admin` token holding all three `review:*` abilities:**
```text
forged_call_status=403
forged_call_code=review_role_required
forged_call_message=Forbidden. Dedicated External Review AI identity required.
```
Access log row written:
```json
{
    "id": 714,
    "outcome": "denied",
    "ability": "review:read",
    "tool": "search",
    "http_status": 403,
    "denial_reason": "wrong_role:ai_super_admin",
    "token_name": "signoff-forged-1eK7",
    "path": "/api/review-gateway/tools/search",
    "created_at": "2026-07-31 21:36:47"
}
```

**Successful `external_review_ai` call:**
```text
status=200
tool=search
```
Access log row:
```json
{
    "id": 716,
    "outcome": "success",
    "ability": "review:read",
    "tool": "search",
    "http_status": 200,
    "denial_reason": null,
    "token_name": "signoff-ok-SZlz",
    "path": "/api/review-gateway/tools/search",
    "created_at": "2026-07-31 21:37:24"
}
```

(IP values present on rows; omitted here.)

---

## 4. TOKEN EXPIRATION CONFIGURATION

### Config key and value (actual)
Exact key is **`review_gateway.token_default_ttl_days`** (not `token_ttl_days`).

```text
$ php artisan tinker --execute="echo 'token_default_ttl_days='.config('review_gateway.token_default_ttl_days').PHP_EOL; echo 'token_expiry_warning_days='.config('review_gateway.token_expiry_warning_days').PHP_EOL;"

token_default_ttl_days=90
token_expiry_warning_days=14
```

Env override: `REVIEW_AI_TOKEN_TTL_DAYS` (default 90). Warning horizon: `REVIEW_AI_TOKEN_EXPIRY_WARNING_DAYS` (default 14).

### `expires_at` is applied at mint time

From `IssueReviewAiTokenCommand.php` (lines 38–41):
```php
$expiresAt = now()->addDays($ttlDays);
$abilities = $principal->abilities();
$newToken = $user->createToken($name, $abilities, $expiresAt);
```

**Live mint during this capture:**
```text
$ php artisan review-ai:issue-token "6a1-signoff-probe"

Created external_review_ai user external-review-ai@serviceop.system.
Review AI token minted. Store this secret now — it will not be shown again.
token_id=946
token_name=6a1-signoff-probe
actor_user_id=29696
actor_role=external_review_ai
expires_at=2026-10-29T21:35:51+00:00
ttl_days=90
abilities=review:read,review:code-read,review:evidence-write
```
(Plaintext token intentionally omitted from this document; token row later deleted after metadata capture.)

**`personal_access_tokens` row (actual, no hash):**
```json
{
    "id": 946,
    "name": "6a1-signoff-probe",
    "tokenable_id": 29696,
    "abilities": "[\"review:read\",\"review:code-read\",\"review:evidence-write\"]",
    "expires_at": "2026-10-29 21:35:51",
    "created_at": "2026-07-31 21:35:51"
}
```

Contrast: a pre-existing legacy token on `ai_super_admin` still has `expires_at: null` (see migrate-legacy output in §5/§6).

### Expiring-token warning mechanism

Command exists:
```text
review-ai:flag-expiring-tokens        List external_review_ai tokens nearing expiration (no auto-revoke)
```

Schedule (`routes/console.php`):
```php
Schedule::command('review-ai:flag-expiring-tokens')
    ->dailyAt('06:30')
    ->withoutOverlapping();
```

Live run during capture:
```text
$ php artisan review-ai:flag-expiring-tokens
No external_review_ai tokens expire within 14 day(s).
```

---

## 5. MIGRATION NAME AND DEPLOYMENT STEPS

### 6A.1-relevant migrations (chronological / dependency order)

| # | Filename | Purpose |
|---|----------|---------|
| 1 | `2026_07_30_120001_create_review_gateway_access_logs_table.php` | Append-only review access ledger |
| 2 | `2026_07_31_000001_add_external_review_ai_role.php` | Add `external_review_ai` to `users.role` ENUM |
| 3 | `2026_07_31_180001_create_ai_evaluation_runs_table.php` | Evaluation run metadata |
| 4 | `2026_07_31_180002_create_ai_evaluation_findings_table.php` | Evaluation findings (FK → runs) |
| 5 | `2026_07_31_200001_add_append_only_ledger_triggers.php` | DB triggers on review + evaluation ledgers **and** `learning_gateway_access_logs` (shared Phase 6 hardening) |

**Note:** If Learning Gateway (6B) tables are not yet present in a given environment, migration #5 still expects `learning_gateway_access_logs` to exist when triggers are applied. In this DB, Learning migrations were already applied; production must either run 6B learning ledger migration first or ensure that table exists before #5.

Related Learning migration (not 6A identity, but trigger dependency):  
`2026_07_31_120002_create_learning_gateway_access_logs_table.php`

### Ordered production deployment commands

```bash
# After deploy of application code that includes the migrations/commands below:

# 1) Apply pending migrations (includes 6A.1 tables, role ENUM, triggers)
php artisan migrate --force

# 2) Confirm append-only triggers are active
php artisan ledger:verify-triggers

# 3) Issue the first production External Review AI token (default TTL = 90 days)
php artisan review-ai:issue-token "production-review-ai-1"
# Store the plaintext secret offline; it is printed once.

# 4) List any legacy review:* tokens attached outside external_review_ai (dry run)
php artisan review-ai:migrate-legacy-tokens

# 5) Only after confirming the list in step 4 is expected, revoke them:
php artisan review-ai:migrate-legacy-tokens --revoke
```

**Live `ledger:verify-triggers` in this environment:**
```text
| Table                        | BEFORE UPDATE | BEFORE DELETE | ... |
| review_gateway_access_logs   | OK            | OK            | ... |
| learning_gateway_access_logs | OK            | OK            | ... |
| ai_evaluation_runs           | OK            | OK            | ... |
| ai_evaluation_findings       | OK            | OK            | ... |
All ledger append-only triggers are present.
```

**Live legacy list in this environment:**
```text
| id  | name       | tokenable_id | user_email                      | user_role      | expires_at | created_at          |
| 367 | debug-code | 7            | ai-super-admin@serviceop.system | ai_super_admin | null       | 2026-07-30 19:15:27 |
Found 1 legacy review token(s). Re-issue via review-ai:issue-token, then revoke these.
Dry run only — pass --revoke to delete the listed tokens.
```

### Relation to API key / secret rotation

**These 6A.1 migrations and artisan commands do not depend on external provider credentials** (OpenAI, Stripe, Twilio, etc.). They only touch internal schema (`users.role`, ledger tables, triggers) and Sanctum personal access tokens.

**Order relative to Trystan’s planned API key/secret rotation does not matter for 6A.1 correctness.** Rotation can proceed independently. Operational recommendation (process, not a code dependency): complete secret rotation before issuing long-lived production Review AI tokens so the production environment’s other credentials are already in their post-rotation state when the Review AI credential is stored.

---

## 6. TOKEN ISSUANCE AND REVOCATION COMMANDS

### Issue (copy-paste ready)
```bash
# Default TTL = 90 days (config review_gateway.token_default_ttl_days)
php artisan review-ai:issue-token "descriptive-name-here"

# Optional TTL override (days)
php artisan review-ai:issue-token "descriptive-name-here" --ttl=30
```

### List legacy / unexpected tokens before revoke
```bash
php artisan review-ai:migrate-legacy-tokens
```

### Revoke — Owner UI
1. Sign in as **owner**
2. Open **Review Center** (`/review-center`)
3. Section **“Issued external_review_ai tokens”**
4. Click **Revoke** on the target row (red text button)
5. Confirm the danger dialog; backend calls `POST /api/admin/review-gateway/tokens/{id}/revoke`

### Revoke — artisan / API fallback
- **Legacy tokens on non-`external_review_ai` users:**
  ```bash
  php artisan review-ai:migrate-legacy-tokens --revoke
  ```
- **Specific active `external_review_ai` token:** there is **no** dedicated `review-ai:revoke-token` artisan command in this codebase. Use Owner UI, or authenticated owner API:
  ```bash
  # Example shape (owner Sanctum bearer required):
  POST /api/admin/review-gateway/tokens/{id}/revoke
  ```

### What happens immediately after revocation (plain English)

The token row is deleted from `personal_access_tokens`, so Sanctum can no longer authenticate that bearer string. The next Review Gateway request with the old secret is rejected (401 or 403). An `audit_logs` entry with `action_type=review_gateway_token_revoked` is written for the owner action. Proven by **`ReviewCenterAdminTest::test_3_revoke_invalidates_review_token`** (PASS during this capture): asserts OK before revoke, row missing after revoke, audit row present, subsequent `GET /api/review-gateway/tools/search` status in `{401, 403}`.

```text
$ php artisan test --filter="ReviewCenterAdminTest::test_3_revoke"
   PASS  Tests\Feature\ReviewGateway\ReviewCenterAdminTest
  ✓ 3 revoke invalidates review token
  Tests:    1 passed (6 assertions)
```

---

## Verification Notes

1. **`php artisan route:list --path=review-gateway` failed** in this environment with `ReflectionException: Class "PmMeetingController" does not exist`. Route evidence was captured via the Route facade instead. Production route:list may work if that controller class is present on the deployed branch; this sandbox’s failure is real and unrelated to 6A.1 routes themselves (those routes are registered and callable).

2. **Config key naming:** Trystan’s suggested `config('review_gateway.token_ttl_days')` is **not** the live key; the live key is `token_default_ttl_days` (= **90** here).

3. **Environment:** Evidence taken against local/dev MySQL `hsop_job_command` as `root`. Production TTL, actor email (`REVIEW_AI_ACTOR_EMAIL`), and existing legacy token inventory may differ; re-run `ledger:verify-triggers`, `review-ai:migrate-legacy-tokens`, and `review-ai:flag-expiring-tokens` on production after migrate.

4. **Probe tokens:** `6a1-signoff-probe` (id 946) and temporary forged/success tokens used for live HTTP evidence were deleted after metadata capture. The `external_review_ai` user id 29696 remains in this DB (`external-review-ai@serviceop.system`).

5. **Legacy token id 367** (`debug-code` on `ai_super_admin`, `expires_at=null`) still exists in this DB; `--revoke` was **not** run during sign-off capture to avoid changing shared-dev state beyond what evidence required.

6. **No application code, routes, or configuration were modified** for this evidence task (temporary local scratch PHP scripts used for capture were removed afterward).

---

*End of 6A.1 sign-off evidence bundle.*
