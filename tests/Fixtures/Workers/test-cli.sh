#!/usr/bin/env bash
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
for args in '--server=unknown' '--requests=0' '--jobs=0' '--requests=4999 --memory-check' '--requests=5000 --jobs=4999 --memory-check' '--report='; do
    # These are fixed fixture strings, deliberately split into separate CLI arguments.
    read -r -a words <<< "$args"
    status=0
    bash "$root/bin/worker-smoke.sh" "${words[@]}" >/dev/null 2>&1 || status=$?
    if [[ "$status" != 2 ]]; then
        echo "invalid arguments accepted: $args (exit $status)" >&2
        exit 1
    fi
done
echo 'worker CLI rejection fixtures passed'
