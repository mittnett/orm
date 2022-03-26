#!/usr/bin/env sh
# vim: tabstop=4 shiftwidth=4 expandtab

set -e

poamn run --detach \
	--name hborm_db \
	--publish "3306:3306" \
	mittnett.net/orm/mariadb:10.5

