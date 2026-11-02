#!/usr/bin/env bash
# Offline negative fixtures for the matrix contract; no dependency installation.
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
php -r '
    foreach (["8.3-12","8.3-13","8.4-12","8.4-13","8.5-13"] as $pair)
        foreach (["lowest","latest"] as $policy)
            foreach (["core","filament","core-only","relocated-audit"] as $composition) {
                $id="$pair-$policy-$composition"; $dir=$argv[1]."/".$id; mkdir($dir);
                file_put_contents("$dir/result.json",json_encode(["id"=>$id,"result"=>"success","qualification"=>"nonrelease","version"=>"2.0.0-rc.1","source"=>["mode"=>"worktree","commit"=>"test","tree"=>"test"]]));
                file_put_contents("$dir/run.log","test\n"); file_put_contents("$dir/composer.lock","{}\n"); file_put_contents("$dir/consumer-composer.json","{}\n");
                file_put_contents("$dir/package.zip","fixture\n"); file_put_contents("$dir/package.zip.list","composer.json\n"); file_put_contents("$dir/package.zip.manifest.json","{}\n");
                file_put_contents("$dir/archives.sha256",hash_file("sha256","$dir/package.zip")."  ./package.zip\n");
            }
' "$tmp"
bash "$root/bin/consumer-matrix.sh" --verify="$tmp" >/dev/null
php -r '$f=$argv[1]; $r=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR); $r["version"]="2.0.0-rc.2"; file_put_contents($f,json_encode($r));' "$tmp/8.3-12-lowest-core/result.json"
if bash "$root/bin/consumer-matrix.sh" --verify="$tmp" >/dev/null 2>&1; then echo 'Mixed versions accepted' >&2; exit 1; fi
php -r '$f=$argv[1]; $r=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR); $r["version"]="2.0.0-rc.1"; file_put_contents($f,json_encode($r));' "$tmp/8.3-12-lowest-core/result.json"
mv "$tmp/8.3-12-lowest-core/result.json" "$tmp/omitted.json"
if bash "$root/bin/consumer-matrix.sh" --verify="$tmp" >/dev/null 2>&1; then echo 'Omitted row accepted' >&2; exit 1; fi
mv "$tmp/omitted.json" "$tmp/8.3-12-lowest-core/result.json"
printf '%s\n' '{"repositories":[{"type":"path","url":"../package"}]}' > "$tmp/8.3-12-lowest-core/consumer-composer.json"
if bash "$root/bin/consumer-matrix.sh" --verify="$tmp" >/dev/null 2>&1; then echo 'Path repository accepted' >&2; exit 1; fi
if bash "$root/bin/consumer-fixture.sh" --policy=unknown >/dev/null 2>&1; then echo 'Unknown policy accepted' >&2; exit 1; fi
git clone --quiet --no-hardlinks "$root" "$tmp/repo"
cp "$root/bin/consumer-matrix.sh" "$tmp/repo/bin/consumer-matrix.sh"
printf 'dirty\n' > "$tmp/repo/untracked-test-file"
if bash "$tmp/repo/bin/consumer-matrix.sh" --source="$(git -C "$tmp/repo" rev-parse HEAD)" --output="$tmp/output" >/dev/null 2>&1; then
    echo 'Dirty release source accepted' >&2; exit 1
fi
# Exercise the acceptance CLI only up to archive creation, without running its matrix.
mkdir -p "$tmp/bin"
printf '#!/usr/bin/env bash\nexit 0\n' > "$tmp/bin/curl"
cat > "$tmp/bin/composer" <<'SH'
#!/usr/bin/env bash
for arg in "$@"; do
    case "$arg" in
        --working-dir=*) php -r '$j=json_decode(file_get_contents($argv[1]."/composer.json"),true,512,JSON_THROW_ON_ERROR); file_put_contents(getenv("MATRIX_TEST_VERSION_FILE"),$j["version"]);' "${arg#*=}" ;;
    esac
done
exit 42
SH
chmod +x "$tmp/bin/curl" "$tmp/bin/composer"
php_pair="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
case "$php_pair" in 8.3|8.4) php_pair="$php_pair-12" ;; 8.5) php_pair="$php_pair-13" ;; *) echo "Unsupported test PHP: $php_pair" >&2; exit 1 ;; esac
if PATH="$tmp/bin:$PATH" MATRIX_TEST_VERSION_FILE="$tmp/version" bash "$root/bin/consumer-matrix.sh" --source=worktree --version=2.0.0-rc.1 --deps=lowest,latest --report="$tmp/report/matrix.json" --pair="$php_pair" >"$tmp/cli.log" 2>&1; then
    echo 'Injected archive failure accepted' >&2; exit 1
fi
[[ "$(<"$tmp/version")" == 2.0.0-rc.1 ]]
php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if ($j["version"] !== "2.0.0-rc.1" || count($j["rows"]) !== 40) exit(1);' "$tmp/report/matrix.json"
# The coordinator supplies a relative report path; it must resolve from the caller's directory.
if (cd "$tmp" && PATH="$tmp/bin:$PATH" MATRIX_TEST_VERSION_FILE="$tmp/relative-version" bash "$root/bin/consumer-matrix.sh" --source=worktree --version=2.0.0-rc.1 --deps=lowest,latest --report=relative/matrix.json --pair="$php_pair" >"$tmp/relative-cli.log" 2>&1); then
    echo 'Injected archive failure accepted for relative report' >&2; exit 1
fi
[[ "$(<"$tmp/relative-version")" == 2.0.0-rc.1 ]]
php -r '$j=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); if ($j["version"] !== "2.0.0-rc.1" || count($j["rows"]) !== 40) exit(1);' "$tmp/relative/matrix.json"
if bash "$root/bin/consumer-matrix.sh" --source=worktree --deps=lowest,unknown --report="$tmp/invalid.json" >/dev/null 2>&1; then echo 'Unknown dependency policy accepted' >&2; exit 1; fi
if bash "$root/bin/consumer-matrix.sh" >"$tmp/usage.log" 2>&1; then echo 'Missing source accepted' >&2; exit 1; fi
usage_text="$(<"$tmp/usage.log")"
for option in --version=VERSION --deps=lowest,latest --report=FILE; do
    [[ "$usage_text" == *"$option"* ]] || { echo "Missing usage option: $option" >&2; exit 1; }
done
echo 'Matrix negative fixtures passed.'
