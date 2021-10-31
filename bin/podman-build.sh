#!/usr/bin/env sh

set -e

current_dir=$(pwd)

cd "$current_dir/containers/php-cli" \
  && podman build -t hborm/php:8.0-cli .

cd "$current_dir/containers/mariadb" \
  && podman build -t hborm/mariadb:10.5 .
