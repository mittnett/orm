#!/usr/bin/env sh

set -e

podman run --detach \
	--name hborm_db \
	--volume hborm_db_data:/var/lib/mysql \
	--env "MYSQL_ROOT_PASSWORD=secret" \
	--env "MYSQL_DATABASE=app" \
	--publish "3306:3306" \
	docker.io/library/mariadb:10.5

