# Fisha.shop local server — run from PowerShell:   .\docker\start.ps1
# Docker prints warnings on stderr; do not let PowerShell treat them as fatal
$ErrorActionPreference = "Continue"
$env:COMPOSE_IGNORE_ORPHANS = "1"
Set-Location (Split-Path $PSScriptRoot -Parent)

Write-Host "Preparing fast volumes for WordPress core/plugins/themes (first time copies ~430MB, ~1-2 min)..." -ForegroundColor Cyan
docker compose run --rm sync 2>&1 | Out-Host

Write-Host "Starting Fisha containers..." -ForegroundColor Cyan
docker compose up -d 2>&1 | Out-Host

Write-Host "Waiting for the database (first start imports fisha.sql, ~1 min)..." -ForegroundColor Cyan
$ok = $false
for ($i = 0; $i -lt 90; $i++) {
    $null = (cmd /c "docker compose run --rm wpcli option get home --skip-plugins --skip-themes >nul 2>&1")
    if ($LASTEXITCODE -eq 0) { $ok = $true; break }
    if ($i % 5 -eq 4) { Write-Host "  still waiting... ($([int](($i+1)*3)) s)" }
    Start-Sleep -Seconds 3
}
if (-not $ok) {
    Write-Host "Database not ready. Last error:" -ForegroundColor Red
    docker compose run --rm wpcli option get home --skip-plugins --skip-themes 2>&1 | Out-Host
    docker compose logs db --tail 15 2>&1 | Out-Host
    exit 1
}

$marker = "docker\db\.urls-replaced"
if (-not (Test-Path $marker)) {
    Write-Host "Pointing site links at http://localhost:8484 ..." -ForegroundColor Cyan
    $local = "http://localhost:8484"
    foreach ($from in @("http://localhost:8080", "https://www.fisha.shop", "http://www.fisha.shop", "https://fisha.shop", "http://fisha.shop")) {
        docker compose run --rm wpcli search-replace $from $local --all-tables --skip-columns=guid --skip-plugins --skip-themes --quiet 2>&1 | Where-Object { $_ -notmatch 'No services to build' } | Out-Host
        $fromEsc = $from -replace "/", "\/"
        $localEsc = $local -replace "/", "\/"
        docker compose run --rm wpcli search-replace $fromEsc $localEsc --all-tables --skip-columns=guid --skip-plugins --skip-themes --quiet 2>&1 | Where-Object { $_ -notmatch 'No services to build' } | Out-Host
    }
    $null = (cmd /c "docker compose run --rm wpcli cache flush --skip-plugins --skip-themes >nul 2>&1")
    New-Item -ItemType File $marker -Force | Out-Null
}

Write-Host ""
Write-Host "Fisha is running:" -ForegroundColor Green
Write-Host "  Site:        http://localhost:8484"
Write-Host "  Admin:       http://localhost:8484/wp-admin  (same login as the live site)"
Write-Host "  phpMyAdmin:  http://localhost:8485"
Start-Process "http://localhost:8484"
