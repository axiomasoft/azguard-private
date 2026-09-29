#!/usr/bin/env python3
"""Capture relevant constraints from official upstream composer.json branches."""
import argparse
from concurrent.futures import ThreadPoolExecutor
import hashlib
import json
from pathlib import Path
from urllib.request import urlopen

OUT = Path(__file__).resolve().parent / "dependency-manifests.json"
URLS = {
    "filament-panels-5.x": "https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/panels/composer.json",
    "filament-support-5.x": "https://raw.githubusercontent.com/filamentphp/filament/5.x/packages/support/composer.json",
    "pest-4.x": "https://raw.githubusercontent.com/pestphp/pest/4.x/composer.json",
    "testbench-9.x": "https://raw.githubusercontent.com/orchestral/testbench/9.x/composer.json",
    "testbench-10.x": "https://raw.githubusercontent.com/orchestral/testbench/10.x/composer.json",
    "testbench-11.x": "https://raw.githubusercontent.com/orchestral/testbench/11.x/composer.json",
    "laravel-13.x": "https://raw.githubusercontent.com/laravel/framework/13.x/composer.json",
}
KEYS = {"php", "composer-runtime-api", "filament/support", "illuminate/contracts", "laravel/framework",
        "livewire/livewire", "orchestra/testbench-core", "pestphp/pest-plugin-mutate", "phpunit/phpunit"}


def fetch(item):
    key, url = item
    with urlopen(url, timeout=30) as response:
        body = response.read()
    manifest = json.loads(body)
    return key, {
        "url": url,
        "checked_date": "2026-09-29",
        "sha256": hashlib.sha256(body).hexdigest(),
        "name": manifest.get("name"),
        "relevant_require": {name: value for name, value in manifest.get("require", {}).items() if name in KEYS},
    }


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    with ThreadPoolExecutor(max_workers=4) as pool:
        data = dict(pool.map(fetch, URLS.items()))
    if args.check:
        if data != json.loads(OUT.read_text()):
            raise SystemExit("Upstream constraints changed; review source versions and regenerate evidence.")
    else:
        OUT.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n")
    print(f"Checked {len(data)} official composer manifests; snapshot match={args.check}")
