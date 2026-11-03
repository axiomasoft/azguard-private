#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode=redis
mode_set=0
fixture_set=0
report_file=''
usage() {
    echo 'usage: production-runbook-drill.sh [redis|--self-test] [--fixture=disposable] [--report=build/PATH.json]' >&2
    exit 2
}
for argument in "$@"; do
    case "$argument" in
        redis|--self-test)
            ((mode_set == 0)) || usage
            mode="$argument"; mode_set=1 ;;
        --fixture=disposable)
            ((fixture_set == 0)) || usage
            fixture_set=1 ;;
        --report=?*)
            [[ -z "$report_file" ]] || usage
            report_file="${argument#*=}" ;;
        *) usage ;;
    esac
done
if [[ -n "$report_file" ]]; then
    # Reports stay below the project build directory; reject traversal and symlink escapes before setup.
    [[ "$report_file" =~ ^build/[a-zA-Z0-9._/-]+\.json$ ]] || usage
    IFS='/' read -r -a report_parts <<< "$report_file"
    report_path="$root"
    for part in "${report_parts[@]}"; do
        [[ -n "$part" && "$part" != . && "$part" != .. ]] || usage
        report_path="$report_path/$part"
        [[ ! -L "$report_path" ]] || usage
    done
    [[ ! -d "$report_path" ]] || usage
    report_file="$report_path"
fi
command -v php >/dev/null
[[ -f "$root/vendor/autoload.php" ]] || { echo 'Install the locked development dependencies first.' >&2; exit 2; }
php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 2);'
mkdir -p "$root/build/production-runbook-drill"
report="$(mktemp -d "$root/build/production-runbook-drill/run.XXXXXXXX")"
report_file="${report_file:-$report/result.json}"
socket_dir=''
container=''
cleanup() {
    local result=$?
    trap - EXIT
    if [[ -n "$container" ]]; then
        docker logs "$container" > "$report/redis.log" 2>&1 || true
        docker stop --time 5 "$container" >> "$report/redis.log" 2>&1 || docker kill "$container" >> "$report/redis.log" 2>&1 || true
        if ! docker container rm "$container" >> "$report/redis.log" 2>&1; then result=1; fi
    fi
    if [[ -n "$socket_dir" ]]; then rmdir -- "$socket_dir" || result=1; fi
    printf '%s\n' "$result" > "$report/exit.txt"
    # Emit the final outcome after cleanup, including setup/test/cleanup failures. Publish atomically.
    php -r '
        [$script, $root, $directory, $output, $mode, $exit] = $argv;
        $relative = substr($directory, strlen($root) + 1);
        $logs = [];
        foreach (["run.log", "tests.log", "commands.log", "redis.log", "versions.txt", "source-files.sha256", "worktree.patch"] as $name) {
            if (is_file($directory."/".$name)) $logs[$name] = $relative."/".$name;
        }
        $sha = is_file($directory."/source-sha.txt") ? trim(file_get_contents($directory."/source-sha.txt")) : null;
        $report = ["schema_version" => 1, "fixture" => "disposable", "mode" => $mode === "redis" ? "redis" : "array",
            "status" => (int) $exit === 0 ? "passed" : "failed", "exit" => (int) $exit,
            "source_sha" => $sha, "evidence_dir" => $relative, "logs" => $logs];
        $temporary = $output.".tmp.".bin2hex(random_bytes(8));
        if (file_put_contents($temporary, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL) === false
            || !rename($temporary, $output)) exit(1);
    ' "$root" "$report" "$report_file" "$mode" "$result" || result=1
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
mkdir -p "$(dirname "$report_file")"
echo "production runbook drill evidence: $report"
echo "production runbook drill report: $report_file"
exec > >(tee "$report/run.log") 2>&1
git -C "$root" rev-parse HEAD > "$report/source-sha.txt"
git -C "$root" diff --binary -- docs/advanced/operations.md docs/advanced/security.md \
    docs/advanced/observability.md RELEASING.md bin/production-runbook-drill.sh \
    tests/Feature/Console/ProductionRunbookTest.php tests/Fixtures/Operations CHANGELOG.md > "$report/worktree.patch"
php -r '$root=$argv[1]; $paths=["docs/advanced/operations.md", "docs/advanced/security.md", "docs/advanced/observability.md", "RELEASING.md", "CHANGELOG.md", "bin/production-runbook-drill.sh", "tests/Feature/Console/ProductionRunbookTest.php"]; foreach (new DirectoryIterator($root."/tests/Fixtures/Operations") as $f) if ($f->isFile()) $paths[]="tests/Fixtures/Operations/".$f->getFilename(); sort($paths); foreach ($paths as $p) echo hash_file("sha256",$root."/".$p)."  ".$p.PHP_EOL;' \
    "$root" > "$report/source-files.sha256"
php -v > "$report/versions.txt"
php -r 'require $argv[1]; foreach (["laravel/framework", "orchestra/testbench", "pestphp/pest"] as $p) echo $p." ".Composer\InstalledVersions::getPrettyVersion($p).PHP_EOL;' \
    "$root/vendor/autoload.php" >> "$report/versions.txt"

if [[ "$mode" == redis ]]; then
    command -v docker >/dev/null
    php -r 'exit(extension_loaded("redis") ? 0 : 2);'
    # No existing Redis endpoint, TCP port, database URL or credentials are accepted.
    socket_dir="$(mktemp -d "$root/build/production-runbook-drill/socket.XXXXXXXX")"
    container="azguard-runbook-$(basename "$report" | tr '[:upper:].' '[:lower:]-')"
    docker run -d --name "$container" --network none --read-only --cap-drop ALL \
        --security-opt no-new-privileges --user "$(id -u):$(id -g)" \
        --mount "type=bind,src=$socket_dir,dst=/drill" \
        redis:7.4-alpine redis-server --port 0 --unixsocket /drill/redis.sock \
        --unixsocketperm 700 --save '' --appendonly no --dir /drill > "$report/container-id.txt"
    docker inspect --format '{{.Image}}' "$container" >> "$report/versions.txt"
    for ((i=0; i<100; i++)); do
        [[ -S "$socket_dir/redis.sock" ]] && break
        sleep 0.1
    done
    [[ -S "$socket_dir/redis.sock" ]] || { echo 'Disposable Redis socket unavailable.' >&2; exit 1; }
fi

# The TestCase also overrides connections before parent setup can touch a database.
# No application .env, cached production config, replica flag or service credentials are inherited.
cd "$root"
env -i PATH="$PATH" HOME="$HOME" APP_ENV=testing DB_CONNECTION=sqlite \
    RUNBOOK_REDIS_SOCKET="${socket_dir:+$socket_dir/redis.sock}" RUNBOOK_REPORT="$report" \
    php -d memory_limit=1G vendor/bin/pest tests/Feature/Console/ProductionRunbookTest.php \
    tests/Feature/Http/DenialDisclosureTest.php tests/Feature/Filament/Gate/DenialDisclosureTest.php \
    --fail-on-skipped --colors=never > "$report/tests.log" 2>&1 || {
        result=$?; echo "Drill failed (exit $result); see $report/tests.log" >&2; exit "$result";
    }
if [[ "$mode" == --self-test ]]; then
    echo 'PASS: local array-store rehearsal; Redis/TLS/ACL/host alert delivery remain unqualified.'
else
    echo 'PASS: disposable restore/reset/Redis rotation/denial/monitoring fixtures.'
fi
