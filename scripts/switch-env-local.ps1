# Switch backend .env to local XAMPP MySQL
$backend = Join-Path (Split-Path -Parent $PSScriptRoot) "backend"
$envFile = Join-Path $backend ".env"
$prodBackup = Join-Path $backend ".env.production"

if (-not (Test-Path $prodBackup)) {
    Copy-Item $envFile $prodBackup
    Write-Host "Saved current .env to .env.production"
}

@(
    'APP_ENV=local',
    'APP_DEBUG=true',
    'APP_URL=http://127.0.0.1:8000',
    'FRONTEND_URL=http://localhost:5173',
    'DB_HOST=127.0.0.1',
    'DB_PORT=3306',
    'DB_DATABASE=hsop_job_command',
    'DB_USERNAME=root',
    'DB_PASSWORD=',
    'SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,127.0.0.1,127.0.0.1:5173',
    'CORS_ALLOWED_ORIGINS=http://localhost:5173,http://127.0.0.1:5173,http://localhost:8000,http://127.0.0.1:8000'
) | ForEach-Object {
    $key = ($_ -split '=', 2)[0]
    $val = ($_ -split '=', 2)[1]
    if ((Get-Content $envFile -Raw) -match "(?m)^$key=.*") {
        (Get-Content $envFile -Raw) -replace "(?m)^$key=.*", "$key=$val" | Set-Content $envFile -NoNewline
    }
}

# Remove SSL mode line if present
(Get-Content $envFile) | Where-Object { $_ -notmatch '^DB_SSLMODE=' } | Set-Content $envFile

Write-Host "Local .env active. Database: hsop_job_command on XAMPP MySQL"
Write-Host "Run: cd backend; php artisan config:clear; php artisan migrate --force"
