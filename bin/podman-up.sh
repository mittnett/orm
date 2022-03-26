#!/usr/bin/env sh
# vim: tabstop=4 shiftwidth=4 expandtab

set -e

podman pod create hborm

podman run \
	--detach \
	--name hborm_mariadb \
	--pod hborm
	mittnett.net/orm/mariadb:10.5
