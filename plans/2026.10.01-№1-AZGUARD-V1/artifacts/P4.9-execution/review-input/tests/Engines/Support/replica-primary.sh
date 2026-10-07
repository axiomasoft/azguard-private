#!/usr/bin/env sh
set -eu
# Only the profile-isolated qualification cluster uses these credentials.
printf 'host replication authority_test all scram-sha-256\n' >> "$PGDATA/pg_hba.conf"
