# Fisha Studio tests

Run from the repo root in Git Bash (Docker must be running):

    bash tests/studio/run.sh            # all tests
    bash tests/studio/run.sh publish    # only tests whose name contains "publish"

## Local test owner (browser checks)

Create once on the local Docker site (never on dev/live):

    PW=$(openssl rand -hex 12)
    MSYS_NO_PATHCONV=1 docker compose run --rm wpcli php -d memory_limit=512M /usr/local/bin/wp fisha-studio create-owner owner@fisha.test --login=studio-owner --password="$PW"
    printf 'login: studio-owner\npassword: %s\n' "$PW" > tests/studio/local-owner.txt

`local-owner.txt` is git-ignored. Sign in at http://localhost:8484/studio/login/
