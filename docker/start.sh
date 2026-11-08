#!/bin/sh
set -eu
mkdir -p /run/mysqld /var/lib/mysql
chown -R mysql:mysql /run/mysqld /var/lib/mysql
if [ ! -d /var/lib/mysql/mysql ]; then
  mariadb-install-db --user=mysql --datadir=/var/lib/mysql --skip-test-db >/dev/null
fi
mariadbd --user=mysql --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock --bind-address=127.0.0.1 --port=3306 &
DB_PID=$!
i=0
until mariadb-admin --socket=/run/mysqld/mysqld.sock ping --silent >/dev/null 2>&1; do
  i=$((i+1)); [ "$i" -lt 60 ] || { echo 'MariaDB startup failed' >&2; exit 1; }
  sleep 1
done
DB_DATABASE=${DB_DATABASE:-back_in_stock}
DB_USERNAME=${DB_USERNAME:-stock_app}
DB_PASSWORD=${DB_PASSWORD:-$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')}
export DB_PASSWORD
php /app/docker/init-db.php
# Ensure PHP connects with TCP rather than the local socket.
export DB_HOST=127.0.0.1
php -r 'require "/app/src/bootstrap.php"; init_db(); echo "Schema ready\n";'
(while true; do php /app/bin-worker.php || true; sleep 5; done) &
WORKER_PID=$!
trap 'kill "$WORKER_PID" "$DB_PID" 2>/dev/null || true; wait' INT TERM EXIT
exec php -S 0.0.0.0:${PORT:-8000} -t /app/public /app/public/router.php
