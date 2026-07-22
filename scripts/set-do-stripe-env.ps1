# Sets Stripe App Platform env vars from local backend/.env via DigitalOcean API.
# Usage:
#   $env:DIGITALOCEAN_ACCESS_TOKEN = 'dop_v1_...'
#   $env:DO_APP_ID = 'xxxxxxxx-xxxx-...'   # optional
#   powershell -ExecutionPolicy Bypass -File scripts/set-do-stripe-env.ps1
#
# Never prints secret values.

$ErrorActionPreference = 'Stop'
$token = $env:DIGITALOCEAN_ACCESS_TOKEN
if (-not $token) { throw 'Set DIGITALOCEAN_ACCESS_TOKEN first.' }

$envPath = Join-Path $PSScriptRoot '..\backend\.env'
$vals = @{}
Get-Content $envPath | ForEach-Object {
  if ($_ -match '^(STRIPE_SECRET_KEY|STRIPE_PUBLISHABLE_KEY|STRIPE_WEBHOOK_SECRET|PAYMENT_PROVIDER|TWILIO_AUTH_TOKEN)=(.*)$') {
    $vals[$matches[1]] = $matches[2].Trim().Trim('"')
  }
}

foreach ($k in @('STRIPE_SECRET_KEY','STRIPE_PUBLISHABLE_KEY','STRIPE_WEBHOOK_SECRET')) {
  if (-not $vals[$k]) { throw "Missing $k in backend/.env" }
}
if (-not $vals['PAYMENT_PROVIDER']) { $vals['PAYMENT_PROVIDER'] = 'stripe' }

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
$svc = $spec.services | Where-Object { $_.name -match 'api|backend|laravel' } | Select-Object -First 1
if (-not $svc) { $svc = $spec.services[0] }
Write-Host "Updating service: $($svc.name)"

$toSet = @{
  STRIPE_SECRET_KEY = $vals['STRIPE_SECRET_KEY']
  STRIPE_PUBLISHABLE_KEY = $vals['STRIPE_PUBLISHABLE_KEY']
  STRIPE_WEBHOOK_SECRET = $vals['STRIPE_WEBHOOK_SECRET']
  PAYMENT_PROVIDER = $vals['PAYMENT_PROVIDER']
}
if ($vals['TWILIO_AUTH_TOKEN']) {
  $toSet['TWILIO_AUTH_TOKEN'] = $vals['TWILIO_AUTH_TOKEN']
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
Write-Host 'App Platform Stripe env update submitted (triggers redeploy).'
