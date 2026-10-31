#!/usr/bin/env bash
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode=smoke
jobs=1000
requests=1000
memory_check=0
report_file=''
usage() {
    echo 'usage: worker-smoke.sh [smoke|memory|soak|self-test] [--server=roadrunner] [--requests=N] [--jobs=N] [--memory-check] [--report=FILE]' >&2
    exit 2
}
if [[ $# -gt 0 && "$1" != --* ]]; then
    mode="$1"
    shift
fi
case "$mode" in
    smoke) requests=1000 ;;
    memory) requests=10000; memory_check=1 ;;
    soak) requests=100000; memory_check=1 ;;
    self-test) requests=10; jobs=10 ;;
    *) usage ;;
esac
for arg in "$@"; do
    case "$arg" in
        --server=roadrunner) ;;
        --requests=*) requests="${arg#*=}" ;;
        --jobs=*) jobs="${arg#*=}" ;;
        --memory-check) memory_check=1 ;;
        --report=?*) report_file="${arg#*=}" ;;
        *) usage ;;
    esac
done
[[ "$requests" =~ ^[1-9][0-9]{0,6}$ && "$jobs" =~ ^[1-9][0-9]{0,6}$ ]] || usage
if ((memory_check && requests < 5000)); then
    echo 'memory check requires at least 5000 measured requests' >&2
    exit 2
fi
measured_requests=$requests
if ((memory_check)); then requests=$((requests+200)); fi
if [[ -n "$report_file" && "$report_file" != /* ]]; then report_file="$PWD/$report_file"; fi
work="$(mktemp -d "${TMPDIR:-/tmp}/azguard-worker.XXXXXXXX")"
mkdir -p "${root}/build/worker-smoke"
report="$(mktemp -d "${root}/build/worker-smoke/${mode}.XXXXXXXX")"
server_pid=''
cleanup() {
    if [[ -n "$server_pid" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf "$work"
}
trap cleanup EXIT
echo "worker report: $report"
exec > >(tee "$report/run.log") 2>&1
echo "mode=$mode requests=$requests jobs=$jobs"
git -C "$root" rev-parse HEAD
php -v
composer --version
export APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= AZGUARD_DB_CONNECTION=sqlite
mkdir -p "$work/package" "$work/dist"
cp -a "$root/packages/core/." "$work/package/"
php -r '$p=$argv[1]; $j=json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR); $j["version"]="2.0.0"; file_put_contents($p,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");' "$work/package/composer.json"
composer archive --format=zip --dir="$work/dist" --working-dir="$work/package" --no-interaction
sha256sum "$work/dist/"*.zip
composer create-project 'laravel/laravel:^12.0' "$work/app" --no-interaction --prefer-dist --no-progress
cd "$work/app"
composer config repositories.azguard artifact "$work/dist"
composer require axiomasoft/azguard:2.0.0 --no-interaction --no-progress
composer require --dev laravel/octane:2.12.0 spiral/roadrunner-cli:2.7.1 spiral/roadrunner-http:3.6.0 \
    --with-all-dependencies --no-interaction --no-progress
vendor/bin/rr get-binary --filter=3.0.1 --no-config --no-interaction
./rr --version
composer show --locked laravel/octane
composer show --locked spiral/roadrunner-cli
composer show --locked spiral/roadrunner-http
[[ ! -L vendor/axiomasoft/azguard ]] || { echo 'archive installation is a symlink' >&2; exit 1; }
cp "$root/tests/Fixtures/Workers/WorkerProvider.php" app/Providers/WorkerProvider.php
cp "$root/tests/Fixtures/Workers/WorkerProbe.php" app/Providers/WorkerProbe.php
cp "$root/tests/Fixtures/Workers/routes.php" routes/worker.php
cp "$root/tests/Fixtures/Workers/enqueue.php" enqueue.php
cp "$root/tests/Fixtures/Workers/rr.yaml" worker-rr.yaml
php -r '$p=$argv[1]; $a=require $p; $a[]=App\Providers\WorkerProvider::class; file_put_contents($p,"<?php\nreturn ".var_export($a,true).";\n");' bootstrap/providers.php
printf '\nrequire __DIR__."/worker.php";\n' >> routes/web.php
export APP_ENV=testing APP_DEBUG=false DB_CONNECTION=sqlite DB_URL= AZGUARD_DB_CONNECTION=sqlite
export DB_DATABASE="$work/app/database/worker_test.sqlite" QUEUE_CONNECTION=database
export WORKER_JOB_RESULTS="$work/job-results.jsonl"
touch "$DB_DATABASE"
php artisan migrate --force --no-interaction
php -r '$db=new PDO("sqlite:".getenv("DB_DATABASE")); $s=$db->prepare("insert into users (id,name,email,password) values (?,?,?,?)"); for($i=1;$i<=4;$i++) $s->execute([$i,"worker","worker$i@example.test","unused"]);'
port="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); if(!$s) exit(1); echo substr(strrchr(stream_socket_get_name($s,false), ":"),1);')"
export LARAVEL_OCTANE=1 APP_BASE_PATH="$work/app"
./rr -c worker-rr.yaml -o "http.address=127.0.0.1:$port" -o "http.pool.max_jobs=$((requests+100))" serve > "$report/server.log" 2>&1 &
server_pid=$!
for ((i=0;i<100;i++)); do
    if curl --silent --fail "http://127.0.0.1:$port/up" >/dev/null; then break; fi
    kill -0 "$server_pid" 2>/dev/null || { echo 'server exited' >&2; exit 1; }
    sleep 0.1
done
curl --silent --fail "http://127.0.0.1:$port/up" >/dev/null
php "$root/tests/Fixtures/Workers/drive.php" http "http://127.0.0.1:$port" "$requests" "$report/http.jsonl"
if ((memory_check)); then
    php "$root/tests/Fixtures/Workers/check-memory.php" "$report/http.jsonl" "$measured_requests" | tee "$report/memory.json"
fi
unset LARAVEL_OCTANE APP_BASE_PATH
php enqueue.php "$jobs"
php artisan queue:work --stop-when-empty --max-jobs="$((jobs+1))" --tries=1 --sleep=0 --no-interaction > "$report/queue.log" 2>&1
php "$root/tests/Fixtures/Workers/drive.php" file "$WORKER_JOB_RESULTS" "$jobs" "$report/jobs.jsonl"
if [[ -n "$report_file" ]]; then
    mkdir -p "$(dirname "$report_file")"
    php -r '
        [$script, $target, $dir, $requests, $jobs, $memory] = $argv;
        $first = static fn ($path) => json_decode((string) fgets(fopen($path, "rb")), true, 512, JSON_THROW_ON_ERROR);
        $http = $first($dir."/http.jsonl"); $queue = $first($dir."/jobs.jsonl");
        $data = ["server" => "roadrunner", "requests" => (int) $requests, "jobs" => (int) $jobs,
            "warmup" => $memory === "1" ? 200 : 0, "http_pid" => $http["pid"], "queue_pid" => $queue["pid"],
            "http_series" => $dir."/http.jsonl", "job_series" => $dir."/jobs.jsonl", "log" => $dir."/run.log",
            "memory" => $memory === "1" ? json_decode(file_get_contents($dir."/memory.json"), true, 512, JSON_THROW_ON_ERROR) : null];
        if (file_put_contents($target, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") === false) exit(1);
    ' "$report_file" "$report" "$measured_requests" "$jobs" "$memory_check"
    echo "summary report: $report_file"
fi
echo 'worker smoke passed'
