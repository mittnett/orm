#!/usr/bin/env sh

set -e

docker run --rm \
	--tty \
	--interactive \
	--env "PUID=$(id -u)" \
	--env "PGID=$(id -g)" \
	--volume $(pwd):/app \
	--workdir /app \
	hborm:latest \
  "$@"

