# Runs ON the Hostinger server (piped over SSH by docker/publish.sh).
set -eo pipefail
trap 'echo "      FAILED (line $LINENO): $BASH_COMMAND" >&2' ERR
cd "$WP_PATH"
command -v wp >/dev/null || { echo "      wp-cli not found on the server" >&2; exit 1; }
W="wp --skip-plugins --skip-themes --quiet"
STAMP=$(date +%Y%m%d-%H%M%S)

echo "      backup: ~/fisha-sync/backup-$TARGET-$STAMP.sql"
if ! wp --skip-plugins --skip-themes db export ~/fisha-sync/backup-$TARGET-$STAMP.sql 2>&1; then
  if [ "$TARGET" = live ]; then echo "      backup failed - stopping, live was NOT changed" >&2; exit 1; fi
  echo "      (backup failed - continuing, this is only the dev site)"
fi
# keep the last 5 backups
for f in $(ls -1t ~/fisha-sync/backup-$TARGET-*.sql 2>/dev/null | tail -n +6); do rm -f "$f"; done

echo "      import"
wp --skip-plugins --skip-themes db import ~/fisha-sync/fisha-export.sql 2>&1
rm -f ~/fisha-sync/fisha-export.sql
$W config set table_prefix "$PREFIX"

echo "      links: http://localhost:8484 -> $URL"
$W search-replace 'http://localhost:8484' "$URL" --all-tables --skip-columns=guid
$W search-replace 'http:\/\/localhost:8484' "$(printf '%s' "$URL" | sed 's#/#\\/#g')" --all-tables --skip-columns=guid
$W core update-db || true
$W rewrite flush || true

if [ "$TARGET" = dev ]; then
  $W option update blog_public 0
  # dev may only be opened as dev.fisha.shop (not as fisha.shop/dev)
  if ! grep -q 'BEGIN Fisha dev host' .htaccess 2>/dev/null; then
    { cat <<'HT'
# BEGIN Fisha dev host
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP_HOST} !^dev\.fisha\.shop$ [NC]
RewriteRule ^ - [F]
</IfModule>
# END Fisha dev host

HT
      cat .htaccess 2>/dev/null || true; } > .htaccess.fisha && mv .htaccess.fisha .htaccess
  fi
fi

(wp litespeed-purge all --quiet 2>/dev/null || $W cache flush) || true
echo "      server done"
