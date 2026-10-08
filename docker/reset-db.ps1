# Wipes the local database and re-imports docker\db\fisha.sql on next start.
Set-Location (Split-Path $PSScriptRoot -Parent)
docker compose down -v
Remove-Item "docker\db\.urls-replaced" -ErrorAction SilentlyContinue
Write-Host "Local database wiped. Run .\docker\start.ps1 to re-import." -ForegroundColor Yellow
