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
