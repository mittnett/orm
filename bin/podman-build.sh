#!/usr/bin/env bash
# vim: tabstop=4 shiftwidth=4 expandtab

set -e

orig_dir=$(pwd)

set -x

cd "$orig_dir/containers/php/cli/" && podman build -t mittnett.net/orm/php:8.0-cli .
cd "$orig_dir/containers/postgres/" && podman build -t mittnett.net/orm/postgres:13 .
cd "$orig_dir/containers/mariadb/" && podman build -t mittnett.net/orm/mariadb:10.5 .
cd "$orig_dir/containers/mysql/" && podman build -t mittnett.net/orm/mysql:8.0 .

