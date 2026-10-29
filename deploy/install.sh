#!/bin/sh
# Installs or updates Startseite on a server with PHP (no Docker).
#
#   ./deploy/install.sh --app-dir DIR --web-dir DIR [--data-dir DIR] [--user USER] [--base-path /startseite]
#                       [--worker] [--chromium] [--password-from-stdin]
#
#   --app-dir   where src/, bin/ and config/ go (outside the web root)
#   --web-dir   the web-accessible folder that receives public/ (document root or a subfolder)
#   --data-dir  database, images, secret key (default: APP_DIR/data; outside the web root)
#   --user      owner of all files and the user the worker runs as (default: current user)
#   --worker    install and start the systemd service for the thumbnail worker (needs root)
#   --chromium  install Chromium via apt for screenshots (needs root, Debian/Ubuntu)
#
# Updating = running the same command again; config/config.php, the data directory and the user are kept.
set -eu

APP_DIR="" WEB_DIR="" DATA_DIR="" RUN_USER="$(id -un)" BASE_PATH="" WORKER=0 CHROMIUM=0 PW_STDIN=0
while [ $# -gt 0 ]; do
    case "$1" in
        --app-dir) APP_DIR=$2; shift 2 ;;
        --web-dir) WEB_DIR=$2; shift 2 ;;
        --data-dir) DATA_DIR=$2; shift 2 ;;
        --user) RUN_USER=$2; shift 2 ;;
        --base-path) BASE_PATH=$2; shift 2 ;;
        --worker) WORKER=1; shift ;;
        --chromium) CHROMIUM=1; shift ;;
        --password-from-stdin) PW_STDIN=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 1 ;;
    esac
done
[ -n "$APP_DIR" ] && [ -n "$WEB_DIR" ] || { sed -n '2,15p' "$0"; exit 1; }
DATA_DIR=${DATA_DIR:-$APP_DIR/data}

SRC=$(cd "$(dirname "$0")/.." && pwd)
PHP=$(command -v php8.4 || command -v php8.3 || command -v php8.2 || command -v php || true)
[ -n "$PHP" ] || { echo "PHP (>= 8.2) not found" >&2; exit 1; }

missing=""
for ext in gd pdo_sqlite curl mbstring sodium dom zip; do
    "$PHP" -m | grep -qix "$ext" || missing="$missing $ext"
done
if [ -n "$missing" ]; then
    echo "Missing PHP extensions for $PHP:$missing" >&2
    echo "Debian/Ubuntu: apt install php-gd php-sqlite3 php-curl php-mbstring php-xml php-zip (sodium ships with PHP 8)" >&2
    exit 1
fi

as_user() {
    if [ "$(id -un)" = "$RUN_USER" ]; then "$@"; else runuser -u "$RUN_USER" -- "$@"; fi
}

echo "==> Copying application to $APP_DIR"
mkdir -p "$APP_DIR/config" "$DATA_DIR" "$WEB_DIR"
rm -rf "$APP_DIR/src" "$APP_DIR/bin"
cp -R "$SRC/src" "$SRC/bin" "$APP_DIR/"
cp "$SRC/config/config.sample.php" "$APP_DIR/config/config.sample.php"
if [ ! -f "$APP_DIR/config/config.php" ]; then
    if [ -n "$BASE_PATH" ]; then BP="'$BASE_PATH'"; else BP="null"; fi
    sed "s#__DIR__ . '/../data'#'$DATA_DIR'#; s#'base_path' => null#'base_path' => $BP#" \
        "$SRC/config/config.sample.php" > "$APP_DIR/config/config.php"
fi

echo "==> Copying web files to $WEB_DIR"
rm -rf "$WEB_DIR/assets"
cp -R "$SRC/public/assets" "$WEB_DIR/assets"
cp "$SRC/public/index.php" "$SRC/public/sw.js" "$SRC/public/.htaccess" "$WEB_DIR/"
printf "<?php return '%s';\n" "$APP_DIR" > "$WEB_DIR/app_path.php"

if [ "$(id -u)" = "0" ]; then
    chown -R "$RUN_USER:$RUN_USER" "$APP_DIR" "$DATA_DIR" "$WEB_DIR"
fi
chmod 750 "$DATA_DIR"

if [ "$CHROMIUM" = "1" ]; then
    echo "==> Installing Chromium"
    [ "$(id -u)" = "0" ] || { echo "--chromium needs root" >&2; exit 1; }
    apt-get update -qq
    apt-get install -y --no-install-recommends chromium fonts-liberation fonts-noto-color-emoji
fi

echo "==> Initialising database"
as_user "$PHP" -r 'require $argv[1]."/src/bootstrap.php"; Db::pdo(); echo "ok\n";' "$APP_DIR" >/dev/null

USERS=$(as_user "$PHP" -r 'require $argv[1]."/src/bootstrap.php"; echo Auth::userCount();' "$APP_DIR")
if [ "$USERS" = "0" ]; then
    if [ "$PW_STDIN" = "1" ]; then
        as_user "$PHP" "$APP_DIR/bin/set_password.php" admin
    elif [ -t 0 ]; then
        echo "==> Create the login (user 'admin' unless you pass another name)"
        printf "Username [admin]: "; read -r U; U=${U:-admin}
        as_user "$PHP" "$APP_DIR/bin/set_password.php" "$U"
    else
        echo "No user yet. Create one with:  $PHP $APP_DIR/bin/set_password.php admin"
    fi
fi

if [ "$WORKER" = "1" ]; then
    [ "$(id -u)" = "0" ] || { echo "--worker needs root" >&2; exit 1; }
    UNIT="startseite-worker"
    [ -n "$BASE_PATH" ] && UNIT="startseite-worker-$(echo "$BASE_PATH" | tr -dc 'a-z0-9')"
    echo "==> Installing systemd service $UNIT"
    cat > "/etc/systemd/system/$UNIT.service" <<EOF
[Unit]
Description=Startseite thumbnail worker
After=network.target

[Service]
Type=simple
User=$RUN_USER
Group=$RUN_USER
ExecStart=$PHP $APP_DIR/bin/worker.php
Restart=always
RestartSec=5
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
    systemctl daemon-reload
    systemctl enable --now "$UNIT"
    systemctl restart "$UNIT"
fi

echo
echo "Done. Open the site in the browser and log in."
