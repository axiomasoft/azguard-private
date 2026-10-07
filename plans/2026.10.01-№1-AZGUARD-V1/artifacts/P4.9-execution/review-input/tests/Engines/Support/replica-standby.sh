#!/usr/bin/env sh
set -eu
mkdir -p "$PGDATA"
chown postgres:postgres "$PGDATA"
chmod 700 "$PGDATA"
if [ ! -s "$PGDATA/PG_VERSION" ]; then
    # No reuse or wipe of the main test cluster. -R installs standby.signal/conninfo.
    gosu postgres pg_basebackup -h authority-primary -U authority_test -D "$PGDATA" -Fp -Xs -P -R
fi
exec docker-entrypoint.sh postgres
