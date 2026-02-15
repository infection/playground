#!/bin/bash

set -e

SCRIPT_DIR="$(dirname "$0")"

docker compose -f docker-compose.prod.yml build --no-cache --force-rm

"$SCRIPT_DIR/push_images.sh"

rm -rf docker.sock
ssh -nNT -L ./docker.sock:/var/run/docker.sock $(cat "$SCRIPT_DIR/../secrets/ssh_user")@$(cat "$SCRIPT_DIR/../secrets/ssh_host") & echo $! > /tmp/tunnel.pid

echo "Waiting for SSH tunnel..."
while [ ! -S ./docker.sock ]; do sleep 0.5; done
echo "SSH tunnel established."

export DOCKER_HOST=unix://$(pwd)/docker.sock
export DOCKER_REGISTRY=maksrafalko
export APP_SECRET=$(cat "$SCRIPT_DIR/../secrets/app_secret")
export DATABASE_NAME=$(cat "$SCRIPT_DIR/../secrets/mysql_db_name")
export DATABASE_USER=$(cat "$SCRIPT_DIR/../secrets/mysql_db_user")

if [ "${RECREATE_SECRETS:-0}" = "1" ]; then
    echo "Removing service and secrets before redeployment..."
    docker service rm infection_web || true
    docker secret rm infection_ssl_certificate || true
    docker secret rm infection_ssl_key || true
fi

docker stack deploy -c docker-stack.yml infection --with-registry-auth

