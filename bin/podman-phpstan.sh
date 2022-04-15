#!/usr/bin/env sh
# vim: tabstop=4 shiftwidth=4 expandtab

set -e

temp_volume_name="hborm_phpstan_temp"

if ! podman volume ls --quiet | grep -q "$temp_volume_name" ; then
	podman volume create "$temp_volume_name"
fi

podman run --rm \
	--tty \
	--interactive \
	--volume $(pwd):/srv/app \
	--volume "$temp_volume_name:/tmp" \
	--workdir /srv/app \
	mittnett.net/orm/php:8.0-cli \
	phpstan analyse "$@"

