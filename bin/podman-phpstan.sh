#!/usr/bin/env sh

set -ex

temp_volume_name="hborm_phpstan_temp"

if ! podman volume ls --quiet | grep -q "$temp_volume_name" ; then
  podman volume create "$temp_volume_name"
fi

podman run --rm \
	--tty \
	--interactive \
	--volume "./:/app" \
	--volume "$temp_volume_name:/tmp" \
	--workdir /app \
	hborm/php:8.0-cli \
  	vendor/bin/phpstan analyse

