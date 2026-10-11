#!/bin/bash
# Shared by the deploy scripts: production settings from secrets/ and Docker on the droplet over SSH.

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SECRETS_DIR="${SECRETS_DIR:-$ROOT_DIR/secrets}"

secret() {
    tr -d '\n' < "$SECRETS_DIR/$1"
}

export COMPOSE_PROJECT_NAME=infection-playground
export IMAGE_TAG="${IMAGE_TAG:-latest}"

export APP_SECRET="$(secret app_secret)"
export MYSQL_DATABASE="$(secret mysql_db_name)"
export MYSQL_USER="$(secret mysql_db_user)"
export MYSQL_PASSWORD="$(secret mysql_password)"
export MYSQL_ROOT_PASSWORD="$(secret mysql_root_password)"
MYSQL_PASSWORD_URLENCODED="$(python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$MYSQL_PASSWORD")"
export MYSQL_PASSWORD_URLENCODED

PROD_DOCKER_HOST="ssh://$(secret ssh_user)@$(secret ssh_host)"

# Runs docker against the droplet
remote_docker() {
    DOCKER_HOST="$PROD_DOCKER_HOST" docker "$@"
}

remote_compose() {
    DOCKER_HOST="$PROD_DOCKER_HOST" docker compose \
        --file "$ROOT_DIR/compose.yaml" \
        --file "$ROOT_DIR/compose.prod.yaml" \
        "$@"
}

# The images are built on glibc >= 2.34, which creates threads with clone3: the seccomp profile of
# Docker < 20.10.10 rejects that syscall, so neither FrankenPHP nor MySQL 8.4 could start.
MINIMUM_DOCKER_VERSION=20.10.10

require_supported_docker() {
    local version
    version="$(remote_docker version --format '{{.Server.Version}}')"

    if [ "$(printf '%s\n%s\n' "$MINIMUM_DOCKER_VERSION" "$version" | sort -V | head -n 1)" != "$MINIMUM_DOCKER_VERSION" ]; then
        echo "Docker $version on the droplet is too old, upgrade it to $MINIMUM_DOCKER_VERSION or newer first." >&2
        exit 1
    fi
}
