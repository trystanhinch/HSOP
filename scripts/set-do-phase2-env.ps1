# Sets Phase 2 App Platform env vars from local backend/.env via DigitalOcean API.
# Usage:
#   $env:DIGITALOCEAN_ACCESS_TOKEN = 'dop_v1_...'
#   $env:DO_APP_ID = 'xxxxxxxx-xxxx-...'   # optional if only one app
#   powershell -ExecutionPolicy Bypass -File scripts/set-do-phase2-env.ps1
#
# Never prints secret values.

$ErrorActionPreference = 'Stop'
$token = $env:DIGITALOCEAN_ACCESS_TOKEN
if (-not $token) { throw 'Set DIGITALOCEAN_ACCESS_TOKEN first.' }

$envPath = Join-Path $PSScriptRoot '..\backend\.env'
$vals = @{}
Get-Content $envPath | ForEach-Object {
  if ($_ -match '^(OPENAI_API_KEY|OPENAI_MODEL|AI_PROVIDER|GOOGLE_OAUTH_CLIENT_ID|GOOGLE_OAUTH_CLIENT_SECRET)=(.*)$') {
    $vals[$matches[1]] = $matches[2].Trim().Trim('"')
  }
}

foreach ($k in @('OPENAI_API_KEY','GOOGLE_OAUTH_CLIENT_ID','GOOGLE_OAUTH_CLIENT_SECRET')) {
  if (-not $vals[$k]) { throw "Missing $k in backend/.env" }
}
if (-not $vals['AI_PROVIDER']) { $vals['AI_PROVIDER'] = 'openai' }
if (-not $vals['OPENAI_MODEL']) { $vals['OPENAI_MODEL'] = 'gpt-4o-mini' }

$headers = @{ Authorization = "Bearer $token"; 'Content-Type' = 'application/json' }
$apps = Invoke-RestMethod -Uri 'https://api.digitalocean.com/v2/apps?per_page=50' -Headers $headers
$appId = $env:DO_APP_ID
if (-not $appId) {
  $match = $apps.apps | Where-Object { $_.spec.name -match 'serviceop|hsop' } | Select-Object -First 1
  if (-not $match) { $match = $apps.apps | Select-Object -First 1 }
  $appId = $match.id
  Write-Host "Using app $($match.spec.name) id=$appId"
}

$app = Invoke-RestMethod -Uri "https://api.digitalocean.com/v2/apps/$appId" -Headers $headers
$spec = $app.app.spec

# Find backend service (api)
$svc = $spec.services | Where-Object { $_.name -match 'api|backend|laravel' } | Select-Object -First 1
if (-not $svc) { $svc = $spec.services[0] }
Write-Host "Updating service: $($svc.name)"

$toSet = @{
  OPENAI_API_KEY = $vals['OPENAI_API_KEY']
  OPENAI_MODEL = $vals['OPENAI_MODEL']
  AI_PROVIDER = $vals['AI_PROVIDER']
  GOOGLE_OAUTH_CLIENT_ID = $vals['GOOGLE_OAUTH_CLIENT_ID']
  GOOGLE_OAUTH_CLIENT_SECRET = $vals['GOOGLE_OAUTH_CLIENT_SECRET']
  GOOGLE_REDIRECT_URI = 'https://api.serviceop.ca/oauth/gmail/callback'
  GMAIL_MAILBOX = 'leads@serviceop.ca'
  GMAIL_FETCH_ENABLED = 'true'
}

if (-not $svc.envs) { $svc | Add-Member -NotePropertyName envs -NotePropertyValue @() }
$envs = [System.Collections.ArrayList]@($svc.envs)
foreach ($key in $toSet.Keys) {
  $existing = $envs | Where-Object { $_.key -eq $key } | Select-Object -First 1
  if ($existing) {
    $existing.value = $toSet[$key]
    $existing.type = 'SECRET'
    $existing.scope = 'RUN_AND_BUILD_TIME'
  } else {
    [void]$envs.Add([pscustomobject]@{
      key = $key
      value = $toSet[$key]
      type = 'SECRET'
      scope = 'RUN_AND_BUILD_TIME'
    })
  }
  Write-Host "SET $key (len=$($toSet[$key].Length))"
}
$svc.envs = @($envs)

$body = @{ spec = $spec } | ConvertTo-Json -Depth 40 -Compress
Invoke-RestMethod -Method PUT -Uri "https://api.digitalocean.com/v2/apps/$appId" -Headers $headers -Body $body | Out-Null
Write-Host 'App Platform env update submitted (triggers redeploy).'
