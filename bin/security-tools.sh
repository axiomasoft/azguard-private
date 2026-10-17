#!/usr/bin/env bash
# Pinned security tool runner: bash bin/security-tools.sh <name> -- <args>
# Reads .github/security-tools.lock (override: AZGUARD_TOOLS_LOCK), installs into
# var/tools (override: AZGUARD_TOOLS_DIR) after sha256 verification, then runs the tool.
# Exit 2: unknown tool, bad usage or hash mismatch.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
lock="${AZGUARD_TOOLS_LOCK:-$root/.github/security-tools.lock}"
tools_dir="${AZGUARD_TOOLS_DIR:-$root/var/tools}"

fail() { echo "security-tools: $*" >&2; exit 2; }

[ "$#" -ge 1 ] || fail "usage: security-tools.sh <name> -- <args>"
name="$1"; shift
if [ "${1:-}" = "--" ]; then shift; fi

[ -f "$lock" ] || fail "lock file not found: $lock"

field() {
    python3 -c '
import json, sys
tool = json.load(open(sys.argv[1])).get("tools", {}).get(sys.argv[2])
if tool is None:
    sys.exit(3)
print(tool.get(sys.argv[3], ""))
' "$lock" "$name" "$1"
}

field kind >/dev/null || fail "unknown tool: $name"
kind="$(field kind)"
version="$(field version)"
url="$(field url)"
sha256="$(field sha256)"
bin="$(field bin)"
image="$(field image)"
bin="${bin:-$name}"

verify() {
    local actual
    actual="$(sha256sum "$1" | cut -d' ' -f1)"
    [ "$actual" = "$sha256" ] || fail "sha256 mismatch for $name $version: expected $sha256, got $actual"
}

dest="$tools_dir/$name-$version"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

case "$kind" in
    binary)
        if [ ! -x "$dest/$bin" ]; then
            curl -fsSL --retry 3 -o "$tmp/archive" "$url" || fail "download failed: $url"
            verify "$tmp/archive"
            mkdir -p "$tmp/out" "$tools_dir"
            case "$url" in
                *.zip) unzip -q "$tmp/archive" -d "$tmp/out" ;;
                *.tar.gz|*.tgz) tar -xzf "$tmp/archive" -C "$tmp/out" ;;
                *) cp "$tmp/archive" "$tmp/out/$bin" ;;
            esac
            chmod +x "$tmp/out/$bin"
            rm -rf "$dest"
            mv "$tmp/out" "$dest"
        fi
        "$dest/$bin" "$@"
        ;;
    wheel)
        if [ ! -x "$dest/venv/bin/$bin" ]; then
            wheel="$tmp/${url##*/}"
            curl -fsSL --retry 3 -o "$wheel" "$url" || fail "download failed: $url"
            verify "$wheel"
            rm -rf "$dest"
            mkdir -p "$dest"
            python3 -m venv "$dest/venv"
            "$dest/venv/bin/pip" install --quiet --no-deps "$wheel"
        fi
        "$dest/venv/bin/$bin" "$@"
        ;;
    docker)
        [[ "$image" == *@sha256:* ]] || fail "docker image must be pinned by digest: $image"
        docker run --rm -v "$PWD:/work" -w /work "$image" "$@"
        ;;
    *) fail "unknown kind: $kind" ;;
esac
