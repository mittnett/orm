#!/usr/bin/env sh

set -e

temp_volume_name="hborm_phpstan_temp"

if ! docker volume ls --quiet | grep -q "$temp_volume_name" ; then
  docker volume create "$temp_volume_name"
fi

docker run --rm \
	--tty \
	--interactive \
	--env "PUID=$(id -u)" \
	--env "PGID=$(id -g)" \
	--volume $(pwd):/app \
	--volume "$temp_volume_name:/tmp" \
	--workdir /app \
	hborm:latest \
  vendor/bin/phpstan analyse

