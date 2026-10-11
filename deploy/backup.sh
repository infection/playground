#!/bin/bash
# Dumps the production database to backups/ on this machine.
#
# Usage: deploy/backup.sh

set -euo pipefail

source "$(dirname "$0")/common.sh"

BACKUP_FILE="$ROOT_DIR/backups/infection-playground-$(date -u +%Y%m%dT%H%M%SZ).sql"
mkdir -p "$ROOT_DIR/backups"

# shellcheck disable=SC2016
remote_compose exec -T database sh -c \
    'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysqldump --single-transaction --quick --routines --triggers --no-tablespaces --default-character-set=utf8mb4 -uroot "$MYSQL_DATABASE"' \
    > "$BACKUP_FILE"

if ! tail -n 1 "$BACKUP_FILE" | grep -q '^-- Dump completed'; then
    echo "The dump is incomplete: $BACKUP_FILE" >&2
    exit 1
fi

echo "$BACKUP_FILE"
