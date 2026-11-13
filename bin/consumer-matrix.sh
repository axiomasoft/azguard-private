#!/usr/bin/env bash
# Build each candidate from one source tree and retain each consumer's solver evidence.
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
source_ref="" output="" pair="" verify="" report="" version="1.0.0" deps="lowest,latest"
for arg in "$@"; do
    case "$arg" in
        --source=?*) source_ref="${arg#*=}" ;;
        --output=?*) output="${arg#*=}" ;;
        --pair=?*) pair="${arg#*=}" ;;
        --verify=?*) verify="${arg#*=}" ;;
        --version=?*) version="${arg#*=}" ;;
        --deps=?*) deps="${arg#*=}" ;;
        --report=?*) report="${arg#*=}" ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

# Verification accepts a directory of row directories, e.g. downloaded CI artifacts.
if [[ -n "$verify" ]]; then
    [[ -n "$report" ]] || report="$verify/candidate.json"
    php -r '
        $expected=[];
        foreach (["8.3-13","8.4-13","8.5-13"] as $pair)
            foreach (["lowest","latest"] as $policy)
                foreach (["core","core-only","relocated-audit"] as $composition)
                    $expected["$pair-$policy-$composition"]=true;
        $source=null; $version=null; $seen=[];
        foreach (glob($argv[1]."/*/result.json") as $file) {
            $r=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
            $id=$r["id"] ?? "";
            if (!isset($expected[$id]) || isset($seen[$id]) || ($r["result"] ?? "") !== "success" || ($r["qualification"] ?? "") !== "nonrelease") throw new RuntimeException("Unexpected, duplicate or unsuccessful row $id");
            if ($source !== null && $source !== ($r["source"] ?? null)) throw new RuntimeException("Mixed sources");
            if (!is_string($r["version"] ?? null) || ($version !== null && $version !== $r["version"])) throw new RuntimeException("Missing or mixed package versions");
            $version=$r["version"];
            $source=$r["source"] ?? null;
            if (!is_array($source) || !isset($source["commit"],$source["tree"],$source["mode"])) throw new RuntimeException("Missing source provenance");
            foreach (["run.log","composer.lock","consumer-composer.json","archives.sha256"] as $name)
                if (!is_file(dirname($file)."/".$name) || filesize(dirname($file)."/".$name) === 0) throw new RuntimeException("Missing evidence $id/$name");
            $manifest=json_decode(file_get_contents(dirname($file)."/consumer-composer.json"),true,512,JSON_THROW_ON_ERROR);
            foreach (($manifest["repositories"] ?? []) as $repo)
                if (is_array($repo) && ($repo["type"] ?? "") === "path") throw new RuntimeException("Path repository in $id");
            $lock=json_decode(file_get_contents(dirname($file)."/composer.lock"),true,512,JSON_THROW_ON_ERROR);
            foreach (array_merge($lock["packages"] ?? [],$lock["packages-dev"] ?? []) as $package)
                if (($package["dist"]["type"] ?? "") === "path") throw new RuntimeException("Path dependency in $id");
            $hashes=file(dirname($file)."/archives.sha256",FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
            if (!$hashes) throw new RuntimeException("No archives in $id");
            foreach ($hashes as $line) {
                if (!preg_match("~^([a-f0-9]{64})  \\./([^/]+\\.zip)$~",$line,$m)) throw new RuntimeException("Invalid archive record in $id");
                $archive=dirname($file)."/".$m[2];
                if (!is_file($archive) || hash_file("sha256",$archive) !== $m[1] || !is_file($archive.".list") || !is_file($archive.".manifest.json")) throw new RuntimeException("Invalid archive evidence in $id");
            }
            $seen[$id]=$r;
        }
        $missing=array_diff_key($expected,$seen);
        if ($missing) throw new RuntimeException("Missing rows: ".implode(", ",array_keys($missing)));
        ksort($seen);
        file_put_contents($argv[2],json_encode(["qualification"=>"nonrelease","version"=>$version,"source"=>$source,"rows"=>array_values($seen)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
        echo "All ".count($seen)." rows passed (nonrelease).\n";
    ' "$verify" "$report"
    exit
fi

[[ -n "$source_ref" && ( -n "$output" || -n "$report" ) ]] || { echo 'Use --source=worktree or --source=<40 SHA>, --report=FILE or --output=DIR, optionally --version=VERSION, --deps=lowest,latest and --pair=PHP-LARAVEL' >&2; exit 2; }
[[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z]+([.-][0-9A-Za-z]+)*)?$ ]] || { echo "Invalid version: $version" >&2; exit 2; }
case "$deps" in lowest,latest|latest,lowest) ;; *) echo "Expected both dependency policies, lowest,latest: $deps" >&2; exit 2 ;; esac
case "$pair" in ''|8.3-13|8.4-13|8.5-13) ;; *) echo "Unsupported pair: $pair" >&2; exit 2 ;; esac
commit="$(git -C "$root" rev-parse HEAD)"
tree="$(git -C "$root" rev-parse HEAD^{tree})"
if [[ "$source_ref" != worktree ]]; then
    [[ "$source_ref" =~ ^[0-9a-f]{40}$ && "$source_ref" == "$commit" ]] || { echo 'Release source must be the exact checked-out 40-character commit' >&2; exit 1; }
    [[ -z "$(git -C "$root" status --porcelain --untracked-files=normal)" ]] || { echo 'Release source is dirty' >&2; exit 1; }
fi
[[ -n "$output" ]] || output="$(dirname "$report")"
mkdir -p "$output"
output="$(cd "$output" && pwd)"
if [[ -n "$report" ]]; then
    mkdir -p "$(dirname "$report")"
    report="$(cd "$(dirname "$report")" && pwd)/$(basename "$report")"
else
    report="$output/candidate.json"
fi
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/source"
if [[ "$source_ref" == worktree ]]; then
    # Snapshot only the inputs consumed by the fixture, excluding root vendor and build.
    cp -a "$root/packages" "$root/fixtures" "$tmp/source/"
    mkdir -p "$tmp/source/tests/Fixtures"
    cp -a "$root/tests/Fixtures/Console" "$tmp/source/tests/Fixtures/"
    tree="$(tar --sort=name --mtime='UTC 1970-01-01' --owner=0 --group=0 --numeric-owner -C "$tmp/source" -cf - . | sha256sum | cut -d' ' -f1)"
else
    git -C "$root" archive "$source_ref" | tar -xf - -C "$tmp/source"
    # tests/ is export-ignored, but relocated-audit compares against this tracked
    # fixture. Read it from the selected commit, never from the working tree.
    fixture='tests/Fixtures/Console/commands.json'
    fixture_entry="$(git -C "$root" ls-tree "$source_ref" -- "$fixture")"
    [[ "$fixture_entry" == $'100644 blob '*$'\t'"$fixture" ]] || {
        echo "Missing or unsafe fixture in selected commit: $fixture" >&2; exit 1;
    }
    mkdir -p "$tmp/source/tests/Fixtures/Console"
    git -C "$root" cat-file blob "$source_ref:$fixture" > "$tmp/source/$fixture"
fi
php -r '
    $rows=[]; foreach (["8.3-13","8.4-13","8.5-13"] as $p) foreach (["lowest","latest"] as $d) foreach (["core","core-only","relocated-audit"] as $c) $rows[]=["id"=>"$p-$d-$c","result"=>"pending"];
    file_put_contents($argv[1],json_encode(["qualification"=>"nonrelease","version"=>$argv[5],"source"=>["mode"=>$argv[2],"commit"=>$argv[3],"tree"=>$argv[4]],"rows"=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
' "$report" "$source_ref" "$commit" "$tree" "$version"
pairs=(8.3-13 8.4-13 8.5-13)
[[ -z "$pair" ]] || pairs=("$pair")
for current in "${pairs[@]}"; do
    php_version="${current%-*}" laravel="${current#*-}"
    actual="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    if [[ "$actual" != "$php_version" ]] && command -v "php$php_version" >/dev/null; then
        mkdir -p "$tmp/php-bin"
        ln -sf "$(command -v "php$php_version")" "$tmp/php-bin/php"
        export PATH="$tmp/php-bin:$PATH"
        actual="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    fi
    [[ "$actual" == "$php_version" ]] || { echo "PHP $php_version required for $current, found $actual" >&2; exit 1; }
    for policy in lowest latest; do
        for composition in core core-only relocated-audit; do
            id="$current-$policy-$composition" row="$output/$current-$policy-$composition"
            [[ ! -e "$row" ]] || { echo "Refusing to overwrite evidence: $row" >&2; exit 1; }
            mkdir -p "$row" "$tmp/dist"
            args=("--laravel=$laravel" "--version=$version" "--policy=$policy" "--source-root=$tmp/source" "--dist=$tmp/dist" "--evidence=$row")
            case "$composition" in core-only|relocated-audit) args+=("$composition");; esac
            # The first row also proves installation with a fresh, isolated Composer cache.
            mkdir -p "$tmp/cache-$id"
            COMPOSER_CACHE_DIR="$tmp/cache-$id" bash "$root/bin/consumer-fixture.sh" "${args[@]}" >"$row/run.log" 2>&1 || { echo "Failed: $id (see $row/run.log)" >&2; exit 1; }
            php -r '
                $r=["id"=>$argv[2],"result"=>"success","qualification"=>"nonrelease","source"=>["mode"=>$argv[3],"commit"=>$argv[4],"tree"=>$argv[5]],"version"=>$argv[6],"php"=>PHP_VERSION,"composer"=>trim(shell_exec("composer --version 2>&1"))];
                file_put_contents($argv[1],json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
            ' "$row/result.json" "$id" "$source_ref" "$commit" "$tree" "$version"
            php -r '$f=$argv[1]; $j=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR); foreach ($j["rows"] as &$row) if ($row["id"] === $argv[2]) $row["result"]="success"; file_put_contents($f,json_encode($j,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");' "$report" "$id"
        done
    done
done
if [[ -z "$pair" ]]; then bash "$0" --verify="$output" --report="$report"; fi
