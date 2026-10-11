#!/bin/bash
# One-time switch of production from the legacy swarm stack (nginx + php-fpm + MySQL 5.7)
# to the symfony-docker setup (FrankenPHP/Caddy + MySQL 8.4).
#
# MySQL 5.7 data files cannot be opened by 8.4 directly, so the data is moved with a dump:
#   1. dump the 5.7 database to backups/ on this machine (and count the rows)
#   2. stop the swarm stack (the downtime starts here)
#   3. start MySQL 8.4 on a new, empty volume and load the dump into it
#   4. check the row counts, then start the app (it runs the Doctrine migrations)
#
# The legacy volume "infection_db-data" is never touched: to roll back, run
# `docker compose down` for the new project and `docker stack deploy` the legacy stack again
# (git show master:docker-stack.yml).
#
# Usage: deploy/migrate-from-swarm.sh            the real thing, asks before step 2
#        DRY_RUN=1 deploy/migrate-from-swarm.sh  step 1 only: changes nothing on the server or in the registry

set -euo pipefail

source "$(dirname "$0")/common.sh"

TABLES=(example ast_run)
BACKUP_FILE="$ROOT_DIR/backups/infection-playground-$(date -u +%Y%m%dT%H%M%SZ).sql"
mkdir -p "$ROOT_DIR/backups"

legacy_db_container() {
    remote_docker ps --quiet --filter 'label=com.docker.swarm.service.name=infection_db' | head -n 1
}

# Prints "table count" lines. The legacy container has the root password in a file, the new one in a variable.
count_rows() {
    local query=''
    for table in "${TABLES[@]}"; do
        query+="SELECT '$table', COUNT(*) FROM \`$table\`; "
    done

    # shellcheck disable=SC2016
    "$@" sh -c '
        password="${MYSQL_ROOT_PASSWORD:-}"
        if [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ]; then password="$(cat "$MYSQL_ROOT_PASSWORD_FILE")"; fi
        exec mysql --batch --skip-column-names -uroot -p"$password" "$MYSQL_DATABASE" -e "$1"
    ' sh "$query" 2>/dev/null
}

if [ "${DRY_RUN:-0}" != '1' ]; then
    echo '0. Building and pushing the production image'
    docker compose --file "$ROOT_DIR/compose.yaml" --file "$ROOT_DIR/compose.prod.yaml" build --pull php
    docker compose --file "$ROOT_DIR/compose.yaml" --file "$ROOT_DIR/compose.prod.yaml" push php
fi

LEGACY_DB="$(legacy_db_container)"
if [ -z "$LEGACY_DB" ]; then
    echo 'The legacy MySQL container (service infection_db) is not running.' >&2
    exit 1
fi

echo "1. Dumping the legacy database to $BACKUP_FILE"
remote_docker exec "$LEGACY_DB" sh -c \
    'exec mysqldump --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 -uroot -p"$(cat "$MYSQL_ROOT_PASSWORD_FILE")" "$MYSQL_DATABASE"' \
    > "$BACKUP_FILE"

if ! tail -n 1 "$BACKUP_FILE" | grep -q '^-- Dump completed'; then
    echo "The dump is incomplete, nothing was changed on the server: $BACKUP_FILE" >&2
    exit 1
fi

LEGACY_COUNTS="$(count_rows remote_docker exec "$LEGACY_DB")"
echo "Rows in the legacy database:"
echo "$LEGACY_COUNTS"

if [ "${DRY_RUN:-0}" = '1' ]; then
    echo 'DRY_RUN=1: stopping here, nothing was changed on the server.'
    exit 0
fi

read -r -p 'Stop the legacy stack and switch production now? Type "yes": ' CONFIRMATION
[ "$CONFIRMATION" = 'yes' ] || exit 1

echo '2. Removing the legacy swarm stack (its volumes are kept)'
remote_docker stack rm infection
while [ -n "$(remote_docker ps --quiet --filter 'label=com.docker.stack.namespace=infection')" ]; do
    sleep 2
done

echo '3. Starting MySQL 8.4 and loading the dump'
remote_compose pull
remote_compose up --detach --wait --no-build database

if [ -n "$(remote_compose exec -T database sh -c 'exec mysql --batch --skip-column-names -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SHOW TABLES"' 2>/dev/null)" ]; then
    echo 'The new database is not empty, refusing to load the dump into it.' >&2
    exit 1
fi

remote_compose exec -T database sh -c 'exec mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' < "$BACKUP_FILE"

NEW_COUNTS="$(count_rows remote_compose exec -T database)"
echo "Rows in the new database:"
echo "$NEW_COUNTS"

if [ "$LEGACY_COUNTS" != "$NEW_COUNTS" ]; then
    echo 'The row counts differ: the app is not started. Roll back as described at the top of this script.' >&2
    exit 1
fi

echo '4. Starting the app'
remote_compose up --detach --wait --no-build
remote_compose ps
remote_compose exec -T php bin/console doctrine:migrations:status
