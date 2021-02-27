#!/usr/bin/env sh

set -e

bin/docker-exec.sh vendor/bin/phpstan analyse

