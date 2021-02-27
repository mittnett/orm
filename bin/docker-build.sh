#!/usr/bin/env sh

set -e

cd ./docker/php-cli \
  && DOCKER_BUILDKIT=1 docker build -t hborm:latest .
