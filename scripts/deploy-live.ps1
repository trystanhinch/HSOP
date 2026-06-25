# HSOP M3 Live Deploy Packager
$ErrorActionPreference = "Stop"
$root = Split-Path -Parent $PSScriptRoot
$deployDir = Join-Path $root "deploy"
New-Item -ItemType Directory -Force -Path $deployDir | Out-Null

Write-Host "Building frontend..."
Push-Location (Join-Path $root "frontend")
npm run build
Pop-Location

Write-Host "Copying frontend dist..."
$feOut = Join-Path $deployDir "hsop-frontend-dist"
if (Test-Path $feOut) { Remove-Item $feOut -Recurse -Force }
Copy-Item (Join-Path $root "frontend\dist") $feOut -Recurse

Write-Host "Zipping backend (excludes vendor, node_modules, .env)..."
$beZip = Join-Path $deployDir "hsop-m3-backend.zip"
if (Test-Path $beZip) { Remove-Item $beZip -Force }

$backend = Join-Path $root "backend"
$exclude = @('vendor', 'node_modules', '.env', 'storage\logs\*', 'storage\framework\cache\*')
Compress-Archive -Path @(
    (Join-Path $backend "app"),
    (Join-Path $backend "bootstrap"),
    (Join-Path $backend "config"),
    (Join-Path $backend "database"),
    (Join-Path $backend "public"),
    (Join-Path $backend "resources"),
    (Join-Path $backend "routes"),
    (Join-Path $backend "scripts"),
    (Join-Path $backend "storage"),
    (Join-Path $backend "artisan"),
    (Join-Path $backend "composer.json"),
    (Join-Path $backend "composer.lock")
) -DestinationPath $beZip -Force

Write-Host ""
Write-Host "DONE. Upload packages from: $deployDir"
Write-Host "  - hsop-m3-backend.zip"
Write-Host "  - hsop-frontend-dist/"
Write-Host "See scripts/DEPLOY-LIVE.md for next steps."
