#!/usr/bin/env sh
# vim: tabstop=4 shiftwidth=4 expandtab

set -e

podman run --rm \
	--tty \
	--interactive \
	--volume $(pwd):/srv/app \
	--workdir /srv/app \
	mittnett.net/orm/php:8.0-cli \
	"$@"

