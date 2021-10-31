#!/usr/bin/env sh

PUID=${PUID:-911}
PGID=${PGID:-911}

if [ "$PUID" != "911" ] ; then
	usermod -o -u "$PUID" abc
fi

if [ "$PGID" != "911" ] ; then
	groupmod -o -g "$PGID" abc
fi

chown abc:abc /app

if [ "$#" = 0 ] ; then
	echo "Welcome"
	exit 0
fi

exec /bin/su - abc \
  /bin/bash -c "cd $(pwd) && $*"
