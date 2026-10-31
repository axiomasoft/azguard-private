#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p build
tmp=$(mktemp -d "$PWD/build/p1-preflight.XXXXXX")
trap 'rm -rf "$tmp"' EXIT
sha=$(git rev-parse HEAD)

case_check() {
    local name=$1 key=$2 expected=$3
    shift 3
    local report="$tmp/$name.json"
    local rc=0
    env -u AUTHORITY_REPLICA_TEST -u DATABASE_URL -u DB_URL -u DB_HOST -u DB_PORT -u TEST_TOKEN -u REDIS_PREFIX -u QUALIFICATION_REDIS -u DOCKER_HOST -u DOCKER_CONTEXT APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: QUALIFICATION_SOURCE_SHA="$sha" "$@" bin/qualification-preflight.sh --read-only --report="$report" >"$tmp/$name.log" 2>&1 || rc=$?
    python3 - "$report" "$key" "$expected" "$rc" "$name" <<'PY'
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
if sys.argv[5] == 'prepared':
    assert int(sys.argv[4]) == 0, report
    assert report['status'] == 'passed', report
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
python3 bin/test-qualification-sqlite-migrations.py

# Controlled prerequisite responses; the migration probe itself uses the real PHP runtime.
real_php=$(command -v php)
mkdir "$tmp/prepared"
python3 - "$tmp/docker.sock" <<'PY'
import socket, sys
s = socket.socket(socket.AF_UNIX)
s.bind(sys.argv[1])
s.close()
PY
export QUALIFICATION_FIXTURE_SOCKET="$tmp/docker.sock"
# The prepared fixture describes a clean source; the executor tree may contain edits.
real_git=$(command -v git)
export QUALIFICATION_REAL_GIT="$real_git"
cat > "$tmp/prepared/git" <<'SH'
#!/usr/bin/env bash
if [[ ${1-} == status ]]; then exit 0; fi
exec "$QUALIFICATION_REAL_GIT" "$@"
SH
chmod +x "$tmp/prepared/git"
cat > "$tmp/prepared/php" <<'SH'
#!/usr/bin/env bash
if [[ ${1-} == -r && ${2-} == *get_loaded_extensions* ]]; then
    printf '%s' '{"extensions":["pdo_sqlite","pcov"],"pcov_enabled":"1","xdebug_mode":"off"}'
elif [[ ${1-} == --version ]]; then
    printf '%s\n' 'PHP fixture'
else
    exec "$QUALIFICATION_REAL_PHP" "$@"
fi
SH
chmod +x "$tmp/prepared/php"
cp "$tmp/prepared/php" "$tmp/prepared/php8.4"
for tool in composer node npm actionlint zizmor gh docker; do
    printf '#!/usr/bin/env bash\nprintf "fixture\\n"\n' > "$tmp/prepared/$tool"
    chmod +x "$tmp/prepared/$tool"
done
cat > "$tmp/prepared/docker" <<'SH'
#!/usr/bin/env bash
if [[ ${1-} == context ]]; then
    printf '[{"Endpoints":{"docker":{"Host":"unix://%s"}}}]\n' "$QUALIFICATION_FIXTURE_SOCKET"
else
    printf 'fixture\n'
fi
SH
chmod +x "$tmp/prepared/docker"
case_check prepared migrations passed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php"

# Exercise the ownership and exact-worker gates using controlled read-only probes.
cat > "$tmp/prepared/docker" <<'SH'
#!/usr/bin/env bash
if [[ ${1-} == context ]]; then
    printf '[{"Endpoints":{"docker":{"Host":"unix://%s"}}}]\n' "$QUALIFICATION_FIXTURE_SOCKET"
elif [[ ${1-} == compose && ${8-} == ps ]]; then
    case "${10-}" in
        authority-primary) printf '%s\n' bbbbbbbbbbbb ;;
        authority-replica) printf '%s\n' cccccccccccc ;;
        *) printf '%s\n' aaaaaaaaaaaa ;;
    esac
elif [[ ${1-} == compose && ${8-} == config ]]; then
    printf '%s canonical\n' "${10-}"
elif [[ ${1-} == volume && ${2-} == inspect ]]; then
    python3 - "$3" <<'PY'
import json, sys
print(json.dumps([{'Labels': {'com.docker.compose.project': 'fixture', 'com.docker.compose.volume': sys.argv[1]}}]))
PY
elif [[ ${1-} == inspect ]]; then
    python3 - "$2" <<'PY'
import json, os, sys
service = {'bbbbbbbbbbbb': 'authority-primary', 'cccccccccccc': 'authority-replica'}.get(sys.argv[1], os.environ.get('FIXTURE_SERVICE', 'postgres'))
target_port, host_port = {'redis': ('6379', '26379'), 'authority-primary': ('5432', '25433'), 'authority-replica': ('5432', '25434')}.get(service, ('5432', '25432'))
volume = {'authority-primary': 'authority_primary_test', 'authority-replica': 'authority_replica_test'}.get(service)
print(json.dumps([{'Config': {'Labels': {'com.docker.compose.service': service,
    'com.docker.compose.project': 'fixture', 'com.docker.compose.project.working_dir': os.environ.get('FIXTURE_WORKDIR', os.getcwd()),
    'com.docker.compose.project.config_files': os.getcwd()+'/docker-compose.yml', 'com.docker.compose.config-hash': 'canonical'},
    'Env': ['PGDATA=/var/lib/postgresql/data']},
    'NetworkSettings': {'Ports': {target_port+'/tcp': [{'HostIp': '127.0.0.1', 'HostPort': host_port}]}, 'Networks': {'fixture': {'IPAddress': '172.18.0.2'}}},
    'State': {'Status': 'running', 'Health': {'Status': 'healthy'}},
    'Mounts': ([{'Destination': '/var/lib/postgresql/data', 'Type': 'volume', 'Name': volume}] if volume else
               [{'Destination': '/data', 'Type': 'volume', 'Name': 'redis_data'}] if service == 'redis' else []),
    'HostConfig': {'Tmpfs': {'/var/lib/postgresql/data': ''}}}]))
PY
else
    printf '%s\n' fixture
fi
SH
chmod +x "$tmp/prepared/docker"
cat > "$tmp/prepared/php" <<'SH'
#!/usr/bin/env bash
if [[ ${1-} == -r && ${2-} == *get_loaded_extensions* ]]; then
    printf '%s' '{"extensions":["pdo_sqlite","pdo_pgsql","pcov","redis"],"pcov_enabled":"1","xdebug_mode":"off"}'
elif [[ ${1-} == -r && ${2-} == *pg_is_in_recovery* ]]; then
    if [[ ${3-} == 25433 ]]; then
        printf '%s' '{"database":"azguard_authority_test","recovery":false,"cluster":"123456","receiver":[]}'
    else
        printf '{"database":"azguard_authority_test","recovery":%s,"cluster":"123456","receiver":[{"status":"streaming","sender_host":"authority-primary","sender_port":5432}]}' "${FIXTURE_REPLICA_STATE:-true}"
    fi
elif [[ ${1-} == -r && ${2-} == *current_database* ]]; then
    [[ ${5-} == azguard_2_test ]] && printf '%s' "$5"
elif [[ ${1-} == -r && ${2-} == *information_schema.tables* ]]; then
    printf '%s' "${FIXTURE_MIGRATIONS:-0:0}"
elif [[ ${1-} == -r && ${2-} == *'new Redis()'* ]]; then
    if [[ ${2-} == *'count($keys)'* ]]; then printf '%s' "${FIXTURE_REDIS_KEYS:-empty}"; else printf '%s' PONG; fi
elif [[ ${1-} == --version ]]; then
    printf '%s\n' 'PHP fixture'
else
    exec "$QUALIFICATION_REAL_PHP" "$@"
fi
SH
chmod +x "$tmp/prepared/php"
case_check worker database.target passed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql PGSQL_PORT=25432 TEST_TOKEN=2
python3 - "$tmp/worker.json" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))
assert r['checks']['database.target']['detail']['database'] == 'azguard_2_test'
assert r['checks']['migrations']['status'] == 'unavailable' and r['status'] == 'failed'
PY
case_check worker-ready migrations passed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql PGSQL_PORT=25432 TEST_TOKEN=2 FIXTURE_MIGRATIONS=2:7
case_check foreign-stand database.target unavailable PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql PGSQL_PORT=25432 TEST_TOKEN=2 FIXTURE_WORKDIR=/tmp
case_check replica-topology replica passed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql AUTHORITY_REPLICA_TEST=1
case_check replica-ready migrations passed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql AUTHORITY_REPLICA_TEST=1 FIXTURE_MIGRATIONS=2:7
case_check replica-worker replica failed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql AUTHORITY_REPLICA_TEST=1 TEST_TOKEN=2
case_check replica-not-standby replica unavailable PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" DB_CONNECTION=pgsql AUTHORITY_REPLICA_TEST=1 FIXTURE_REPLICA_STATE=false
case_check redis-ready redis.isolation passed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" QUALIFICATION_REDIS=1 FIXTURE_SERVICE=redis
python3 - "$tmp/redis-ready.json" <<'PY'
import json, sys
r = json.load(open(sys.argv[1]))
assert r['checks']['redis.isolation']['status'] == 'passed' and r['status'] == 'passed'
PY
case_check redis-existing redis.isolation failed PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" QUALIFICATION_REDIS=1 FIXTURE_SERVICE=redis FIXTURE_REDIS_KEYS=collision
case_check redis-foreign redis.readiness unavailable PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" QUALIFICATION_REDIS=1 FIXTURE_SERVICE=redis FIXTURE_WORKDIR=/tmp
# The malformed prefix must be rejected even when the controlled Redis service is healthy.
case_check redis-unproven-healthy redis.readiness unavailable PATH="$tmp/prepared:$PATH" QUALIFICATION_REAL_PHP="$real_php" QUALIFICATION_REDIS=1 FIXTURE_SERVICE=redis REDIS_PREFIX=azguard-qualification-example:
python3 bin/test-qualification-preflight.py
printf '%s\n' 'preflight target and negative fixtures passed'
