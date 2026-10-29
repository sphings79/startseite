#!/bin/sh
set -e

chown www-data:www-data "$STARTSEITE_DATA_DIR"

# Create the user on first start from STARTSEITE_USER / STARTSEITE_PASSWORD (ignored once a user exists).
runuser -u www-data -- php /app/bin/set_password.php --from-env

# Thumbnail worker, restarted if it ever exits.
(
    while true; do
        runuser -u www-data -- php /app/bin/worker.php || true
        sleep 5
    done
) &

exec "$@"
