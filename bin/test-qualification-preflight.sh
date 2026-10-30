#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
sha=$(git rev-parse HEAD)

case_check() {
    local name=$1 key=$2 expected=$3
    shift 3
    local report="$tmp/$name.json"
    local rc=0
    env -u AUTHORITY_REPLICA_TEST -u DATABASE_URL -u DB_URL -u DB_HOST -u DB_PORT -u TEST_TOKEN -u REDIS_PREFIX APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: QUALIFICATION_SOURCE_SHA="$sha" "$@" bin/qualification-preflight.sh --read-only --report="$report" >"$tmp/$name.log" 2>&1 || rc=$?
    python3 - "$report" "$key" "$expected" "$rc" <<'PY'
import json, sys
with open(sys.argv[1]) as f: report = json.load(f)
with open('bin/qualification-preflight-format.json') as f: manifest = json.load(f)
with open('bin/qualification-preflight-compat.json') as f: fixture = json.load(f)
for document in (report, fixture):
    assert set(manifest['required']) <= document.keys()
    assert document['schema'] == manifest['schema']
    assert document['qualification'] == manifest['qualification']
    for check in document['checks'].values():
        assert set(manifest['check_required']) <= check.keys()
        assert check['status'] in manifest['check_statuses']
actual = report['checks'][sys.argv[2]]['status']
assert actual == sys.argv[3], (sys.argv[2], actual, sys.argv[3])
if actual != 'passed': assert int(sys.argv[4]) != 0
assert report['qualification'] == 'not performed'
PY
}
case_check sqlite database.target passed
case_check production database.target failed DB_CONNECTION=pgsql PGSQL_DATABASE=production
case_check nonlocal database.target failed DB_CONNECTION=pgsql PGSQL_HOST=192.0.2.1 PGSQL_DATABASE=load_bench
case_check collision redis.prefix failed REDIS_PREFIX=production:
case_check redis-unproven redis.readiness unavailable REDIS_PREFIX=azguard-qualification-example:
case_check source source failed QUALIFICATION_SOURCE_SHA=unknown
case_check driver database.driver failed DB_CONNECTION=unknown
mkdir "$tmp/bin"
printf '#!/usr/bin/env bash\nif [[ "${1-}" == validate ]]; then exit 1; fi\nexit 0\n' > "$tmp/bin/composer"
chmod +x "$tmp/bin/composer"
case_check stale-lock lock failed PATH="$tmp/bin:$PATH"
printf '#!/usr/bin/env bash\nif [[ "${1-}" == -r ]]; then printf "%%s" '\''{"extensions":[],"pcov_enabled":"0","xdebug_mode":"off"}'\''; else exit 0; fi\n' > "$tmp/bin/php"
chmod +x "$tmp/bin/php"
cp "$tmp/bin/php" "$tmp/bin/php8.4"
case_check coverage coverage failed PATH="$tmp/bin:$PATH"
case_check missing-driver database.driver failed PATH="$tmp/bin:$PATH"
printf '%s\n' 'preflight target and negative fixtures passed'
