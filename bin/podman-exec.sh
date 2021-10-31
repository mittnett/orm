#!/usr/bin/env sh

set -e

podman run --rm \
	--tty \
	--interactive \
	--volume $(pwd):/app \
	--workdir /app \
	hborm/php:8.0-cli \
  "$@"

