#!/bin/sh
# Runs inside the "publish" container (see docker-compose.yml). Started by docker/publish.ps1.
set -eu
HOST=147.93.54.254
PORT=65002
SSH_USER=u654862842
case "$TARGET" in
  dev)  WP_PATH=/home/$SSH_USER/domains/fisha.shop/public_html/dev; URL=https://dev.fisha.shop ;;
  live) WP_PATH=/home/$SSH_USER/domains/fisha.shop/public_html;     URL=https://fisha.shop ;;
  *) echo "Unknown target: $TARGET"; exit 1 ;;
esac

apk add --no-cache -q rsync openssh-client bash >/dev/null
mkdir -p /root/.ssh
cp /key /root/.ssh/id_ed25519 && chmod 600 /root/.ssh/id_ed25519
SSH="ssh -p $PORT -o StrictHostKeyChecking=accept-new -o ServerAliveInterval=30"
R="$SSH_USER@$HOST"
# files arrive with normal web permissions (Windows mounts show everything as 777)
RS="rsync -rltz --chmod=D755,F644 --info=stats1 -e"

echo "   checking server..."
$SSH "$R" "test -f '$WP_PATH/wp-config.php'" || { echo "No WordPress found at $WP_PATH - install it there first."; exit 1; }
$SSH "$R" "mkdir -p ~/fisha-sync && chmod 700 ~/fisha-sync"

# local DB is MariaDB 11: drop its client-only first line and its newer collation so older servers accept the dump
sed -e '/enable the sandbox mode/d' -e 's/utf8mb4_uca1400_ai_ci/utf8mb4_unicode_520_ci/g' \
  /site/docker/db/out/fisha-export.sql > /tmp/fisha-export.sql
echo "   -> database file";  $RS "$SSH" /tmp/fisha-export.sql "$R:fisha-sync/fisha-export.sql"
echo "   -> plugins";        $RS "$SSH" --delete /vol/plugins/ "$R:$WP_PATH/wp-content/plugins/"
echo "   -> themes";         $RS "$SSH" --delete /vol/themes/  "$R:$WP_PATH/wp-content/themes/"
echo "   -> uploads (adds/updates, never deletes)"
$RS "$SSH" --exclude 'wc-logs/' --exclude 'cache/' --exclude '*.log' --exclude 'elementor/css/' /site/wp-content/uploads/ "$R:$WP_PATH/wp-content/uploads/"
echo "   -> Fisha design code"
$RS "$SSH" --delete --exclude 'tools/' /site/wp-content/mu-plugins/fisha-design/ "$R:$WP_PATH/wp-content/mu-plugins/fisha-design/"
$RS "$SSH" /site/wp-content/mu-plugins/fisha-design.php "$R:$WP_PATH/wp-content/mu-plugins/fisha-design.php"

echo "   importing on the server..."
$SSH "$R" "TARGET='$TARGET' WP_PATH='$WP_PATH' URL='$URL' PREFIX='$PREFIX' bash -s" < /site/docker/publish-remote.sh
