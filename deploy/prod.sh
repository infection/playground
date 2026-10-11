#!/bin/bash
# Builds and pushes the production image, then (re)starts the app on the droplet.
#
# The first deployment of the symfony-docker setup is done by deploy/migrate-from-swarm.sh instead.

set -euo pipefail

source "$(dirname "$0")/common.sh"

if remote_docker stack ls --format '{{.Name}}' 2>/dev/null | grep -qx infection; then
    echo 'The legacy "infection" swarm stack is still running: run deploy/migrate-from-swarm.sh first.' >&2
    exit 1
fi

docker compose --file "$ROOT_DIR/compose.yaml" --file "$ROOT_DIR/compose.prod.yaml" build --pull php
docker compose --file "$ROOT_DIR/compose.yaml" --file "$ROOT_DIR/compose.prod.yaml" push php

remote_compose pull php
# The entrypoint runs the Doctrine migrations before the new container serves requests.
remote_compose up --detach --wait --no-build

remote_compose ps
