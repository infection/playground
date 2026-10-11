#!/bin/bash
# One-time switch of production from the legacy swarm stack (nginx + php-fpm + MySQL 5.7)
# to the symfony-docker setup (FrankenPHP/Caddy + MySQL 8.4).
#
# MySQL 5.7 data files cannot be opened by 8.4 directly, so the data is moved with a dump:
#   1. stop the legacy app (the downtime starts here), dump the 5.7 database to backups/
#      on this machine and count the rows
#   2. remove the swarm stack
#   3. start MySQL 8.4 on a new, empty volume and load the dump into it
#   4. check the row counts, then start the app (it runs the Doctrine migrations)
#
# The legacy volume "infection_db-data" is never touched: to roll back, run
# `docker compose down` for the new project (without -v), then redeploy the legacy stack from
# master with its deploy/prod.sh: it attaches the same volume again.
#
# Usage: deploy/migrate-from-swarm.sh            the real thing, asks before step 2
#        DRY_RUN=1 deploy/migrate-from-swarm.sh  dump + schema check only: changes nothing on the server or in the registry

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

    run_sql "$query" "$@"
}

# run_sql QUERY DOCKER-EXEC-COMMAND...: runs a query as root in the given MySQL container.
run_sql() {
    local query="$1"
    shift

    # shellcheck disable=SC2016
    "$@" sh -c '
        password="${MYSQL_ROOT_PASSWORD:-}"
        if [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ]; then password="$(cat "$MYSQL_ROOT_PASSWORD_FILE")"; fi
        MYSQL_PWD="$password" exec mysql --batch --skip-column-names -uroot "$MYSQL_DATABASE" -e "$1"
    ' sh "$query"
}

# Checked in the dry run too, so that it reports the problem before the day of the switch.
require_supported_docker

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

dump_legacy_database() {
    echo "Dumping the legacy database to $BACKUP_FILE"
    remote_docker exec "$LEGACY_DB" sh -c \
        'MYSQL_PWD="$(cat "$MYSQL_ROOT_PASSWORD_FILE")" exec mysqldump --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 -uroot "$MYSQL_DATABASE"' \
        > "$BACKUP_FILE"

    if ! tail -n 1 "$BACKUP_FILE" | grep -q '^-- Dump completed'; then
        echo "The dump is incomplete: $BACKUP_FILE" >&2
        exit 1
    fi

    LEGACY_COUNTS="$(count_rows remote_docker exec "$LEGACY_DB")" || {
        echo 'Could not count the rows of the legacy database.' >&2
        exit 1
    }
    echo 'Rows in the legacy database:'
    echo "$LEGACY_COUNTS"
}

if [ "${DRY_RUN:-0}" = '1' ]; then
    echo '1. Dry run: the app keeps serving, rows written from now on are not in this dump'
    dump_legacy_database

    # The new migrations assume this schema; check it before the switch.
    echo 'Schema of the legacy database:'
    run_sql 'SHOW CREATE TABLE example\G SHOW CREATE TABLE ast_run\G' remote_docker exec "$LEGACY_DB"
    echo 'Executed migrations (the app runs the ones that are not listed on start):'
    run_sql 'SELECT version FROM doctrine_migration_versions ORDER BY version' remote_docker exec "$LEGACY_DB"

    echo 'DRY_RUN=1: nothing was changed on the server.'
    exit 0
fi

read -r -p 'Stop the legacy app and switch production now? Type "yes": ' CONFIRMATION
[ "$CONFIRMATION" = 'yes' ] || exit 1

# Nothing can write to the database after this point: the dump below has every row.
echo '1. Stopping the legacy app (the downtime starts here), then dumping its database'
remote_docker service scale --detach=false infection_web=0 infection_php=0
dump_legacy_database

echo '2. Removing the legacy swarm stack (its volumes are kept)'
remote_docker stack rm infection
while [ -n "$(remote_docker ps --quiet --filter 'label=com.docker.stack.namespace=infection')" ]; do
    sleep 2
done

echo '3. Starting MySQL 8.4 and loading the dump'
remote_compose pull
remote_compose up --detach --wait --no-build database

if [ -n "$(run_sql 'SHOW TABLES' remote_compose exec -T database)" ]; then
    echo 'The new database is not empty, refusing to load the dump into it.' >&2
    exit 1
fi

remote_compose exec -T database sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot "$MYSQL_DATABASE"' < "$BACKUP_FILE"

NEW_COUNTS="$(count_rows remote_compose exec -T database)" || {
    echo 'Could not count the rows of the new database: the app is not started.' >&2
    exit 1
}
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
