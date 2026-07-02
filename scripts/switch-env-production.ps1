# Restore production .env from backup
$backend = Join-Path (Split-Path -Parent $PSScriptRoot) "backend"
$envFile = Join-Path $backend ".env"
$prodBackup = Join-Path $backend ".env.production"

if (-not (Test-Path $prodBackup)) {
    Write-Error ".env.production not found. Restore DigitalOcean credentials manually."
    exit 1
}

Copy-Item $prodBackup $envFile -Force
Write-Host "Production .env restored from .env.production"
