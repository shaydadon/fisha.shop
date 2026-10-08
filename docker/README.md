# Fisha.shop on Docker

Requirements: Docker Desktop (running).

Start (PowerShell, inside C:\xampp\htdocs\Fisha):

    .\docker\start.ps1

First start imports `docker\db\fisha.sql` and switches all links to http://localhost:8484.

- Site:        http://localhost:8484
- Admin:       http://localhost:8484/wp-admin  (same login as the live site)
- phpMyAdmin:  http://localhost:8485

Stop:                 docker compose down
Fresh DB from SQL:    .\docker\reset-db.ps1   then   .\docker\start.ps1
New export from live: replace docker\db\fisha.sql, then run reset-db + start.

If PowerShell blocks the script: run once
    Set-ExecutionPolicy -Scope CurrentUser RemoteSigned

## Copy the local site to Hostinger

    .\docker\publish.ps1                 # -> https://dev.fisha.shop
    .\docker\publish.ps1 -Target live    # -> https://fisha.shop (type LIVE to confirm)

Copies the database, plugins, themes, uploads and the Fisha design code, then fixes all
links for the target address. The target's current database is backed up first to
`~/fisha-sync/` on the server (last 5 kept). Needs the local site running and the deploy key at
`%USERPROFILE%\.ssh\fisha_deploy` (also added in hPanel → SSH Access → SSH keys).
Logins on the target become the same as your local site.
