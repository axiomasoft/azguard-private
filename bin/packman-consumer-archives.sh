#!/usr/bin/env bash
# Build the locked Packman packages from the tagged monorepo for an isolated Composer host.
set -Eeuo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
target="${1:?Expected the artifact directory}"
mkdir -p "$target"
target="$(cd "$target" && pwd)"
php -r '
    $lock = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    foreach (array_merge($lock["packages"], $lock["packages-dev"]) as $package) {
        if (str_starts_with($package["name"], "axiomasoft/packman")) {
            echo implode("\t", [$package["dist"]["url"], $package["name"], $package["version"]]), "\n";
        }
    }
' "$root/composer.lock" | while IFS=$'\t' read -r path name version; do
    package=${path##*/}
    archive="$target/${name/\//-}-$version.zip"
    git -C "$root/../packman" archive --format=zip --output="$archive" "v$version:packages/$package"
    php -r '
        $zip = new ZipArchive;
        if ($zip->open($argv[1]) !== true) { throw new RuntimeException("Cannot open ".$argv[1]); }
        $manifest = json_decode($zip->getFromName("composer.json"), true, flags: JSON_THROW_ON_ERROR);
        if ($manifest["name"] !== $argv[2]) { throw new RuntimeException("Unexpected package in ".$argv[1]); }
        $manifest["version"] = $argv[3];
        if (! $zip->addFromString("composer.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") || ! $zip->close()) {
            throw new RuntimeException("Cannot write the package manifest to ".$argv[1]);
        }
    ' "$archive" "$name" "$version"
done
