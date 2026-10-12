#!/bin/bash
# Runs a Symfony console command in the production php container.
#
# Usage: deploy/console.sh <command> [arguments and options]
#   e.g. deploy/console.sh app:example:backfill-stats

set -euo pipefail

source "$(dirname "$0")/common.sh"

if [[ $# -eq 0 ]]; then
    echo 'Usage: deploy/console.sh <command> [arguments and options]' >&2
    exit 1
fi

remote_compose exec -T php bin/console "$@"
