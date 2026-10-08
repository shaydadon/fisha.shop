# Copy the local site (database, plugins, themes, uploads, Fisha design code) to Hostinger.
#
#   .\docker\publish.ps1            -> dev.fisha.shop
#   .\docker\publish.ps1 -Target live   -> fisha.shop (asks you to type LIVE first)
#
# Needs: the local site running (.\docker\start.ps1) and the deploy key at %USERPROFILE%\.ssh\fisha_deploy
# The server's current database is backed up to ~/fisha-sync/ on the server before it is replaced.
param([ValidateSet('dev', 'live')][string]$Target = 'dev')

$ErrorActionPreference = 'Continue'   # docker prints harmless warnings on stderr
$env:COMPOSE_IGNORE_ORPHANS = '1'
Set-Location (Split-Path $PSScriptRoot -Parent)

# run docker compose through cmd so its harmless stderr notices don't show up as red PowerShell errors
function Compose([string]$cmdline) {
    cmd /c "docker compose $cmdline 2>&1" | Where-Object { $_ -notmatch 'No services to build' } | Out-Host
    return $LASTEXITCODE
}

$key = Join-Path $env:USERPROFILE '.ssh\fisha_deploy'
if (-not (Test-Path $key)) { Write-Host "Deploy key not found: $key" -ForegroundColor Red; exit 1 }

if ($Target -eq 'live') {
    Write-Host ''
    Write-Host 'This REPLACES the live site (fisha.shop) database, plugins and themes with your local copy.' -ForegroundColor Red
    Write-Host 'Orders, customers or edits made on live since your last local copy will be lost.' -ForegroundColor Red
    Write-Host 'A backup of the live database is saved on the server first (~/fisha-sync/).' -ForegroundColor Yellow
    if ((Read-Host 'Type LIVE to continue') -cne 'LIVE') { Write-Host 'Cancelled.'; exit 1 }
}

Write-Host "`n[1/3] Exporting the local database..." -ForegroundColor Cyan
New-Item -ItemType Directory -Force 'docker\db\out' | Out-Null
$code = Compose 'run --rm wpcli wp db export /var/www/html/docker/db/out/fisha-export.sql --skip-plugins --skip-themes'
if ($code -ne 0 -or -not (Test-Path 'docker\db\out\fisha-export.sql')) {
    Write-Host 'Database export failed. Is the local site running? (.\docker\start.ps1)' -ForegroundColor Red; exit 1
}
$prefix = (cmd /c "docker compose run --rm wpcli wp db prefix --skip-plugins --skip-themes 2>nul" | Select-Object -Last 1)
if (-not $prefix) { $prefix = 'wp_' }
$prefix = $prefix.Trim()
Write-Host "      table prefix: $prefix"

Write-Host "`n[2/3] Uploading to $Target and importing (first run uploads ~700 MB, later runs only changes)..." -ForegroundColor Cyan
$env:FISHA_TARGET = $Target
$env:FISHA_PREFIX = $prefix
$code = Compose 'run --rm publish'
Remove-Item 'docker\db\out\fisha-export.sql' -ErrorAction SilentlyContinue
if ($code -ne 0) { Write-Host "`nPublish failed - see the messages above." -ForegroundColor Red; exit 1 }

$url = if ($Target -eq 'live') { 'https://fisha.shop' } else { 'https://dev.fisha.shop' }
Write-Host "`n[3/3] Done: $url  (log in with your local admin username/password)" -ForegroundColor Green
Start-Process $url
