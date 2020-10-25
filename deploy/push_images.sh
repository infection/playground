#!/bin/bash

set -e

docker push maksrafalko/infection-playground-php:${BUILD_TAG:-latest}
docker push maksrafalko/infection-playground-nginx:${BUILD_TAG:-latest}
