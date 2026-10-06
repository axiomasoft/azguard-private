"""Run bounded CRM predicate mutations in a separate scratch tree."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import hashlib
from datetime import datetime, timezone


def run():
    repo = Path.cwd()
    output = Path(__file__).resolve().parent / "mutations" / datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%f")
    output.mkdir(parents=True)
    scratch = Path(tempfile.mkdtemp(prefix="azguard-p415-mutations-"))
    for name in ["Fixtures", "Acceptance"]:
        shutil.copytree(repo / "tests" / name, scratch / "tests" / name)
    for name in ["Pest.php", "TestCase.php"]:
        shutil.copy2(repo / "tests" / name, scratch / "tests" / name)
    pest = scratch / "vendor/pestphp/pest/bin/pest"
    pest.parent.mkdir(parents=True)
    shutil.copy2(repo / "vendor/pestphp/pest/bin/pest", pest)
    (scratch / "packages").symlink_to(repo / "packages", target_is_directory=True)
    shutil.copy2(repo / "composer.json", scratch / "composer.json")
    (scratch / "vendor/autoload.php").write_text(
        "<?php\n$loader = require " + repr(str(repo / "vendor/autoload.php")) + ";\n"
        r"$loader->setPsr4('AzGuard\\Tests\\', [dirname(__DIR__).'/tests']);" + "\nreturn $loader;\n"
    )
    (scratch / "bootstrap.php").write_text("<?php\nrequire __DIR__.'/vendor/autoload.php';\n")
    xml = (repo / "phpunit.xml").read_text().replace(
        'bootstrap="vendor/autoload.php"', 'bootstrap="bootstrap.php"'
    )
    (scratch / "phpunit.xml").write_text(xml)
    cases = [
        ("R11", "Guards/Crm/Filters/SellerProjects.php",
         "$query->where('city_id', $runtime->user?->getAttribute('city_id'));",
         "$query->whereRaw('1 = 1');"),
        ("R13", "Guards/Crm/RegionCondition.php",
         "&& ($fields['eligible'] ?? true) === true",
         "|| ($fields['eligible'] ?? true) === true"),
        ("R14", "Guards/Crm/Filters/SellerProjects.php",
         "$query->where('city_id', $runtime->user?->getAttribute('city_id'));",
         "$query->whereRaw('1 = 1');"),
    ]
    results = []
    env = {**os.environ, "DB_CONNECTION": "sqlite"}
    original_hashes = {
        relative: hashlib.sha256((repo / "tests/Fixtures/Crm" / relative).read_bytes()).hexdigest()
        for _, relative, _, _ in cases
    }
    for case, relative, old, new in cases:
        fixture = scratch / "tests/Fixtures/Crm" / relative
        original = fixture.read_text()
        if original.count(old) != 1:
            raise RuntimeError("Mutation must change exactly one predicate: " + case)
        command = [
            "php", "-d", "memory_limit=1G", "vendor/pestphp/pest/bin/pest",
            "tests/Acceptance/Crm/ContextRolesTest.php", "--filter", case,
        ]
        control = subprocess.run(command, cwd=scratch, env=env, capture_output=True, text=True)
        (output / (case + "-control.log")).write_text(control.stdout + control.stderr)
        try:
            fixture.write_text(original.replace(old, new))
            mutant = subprocess.run(command, cwd=scratch, env=env, capture_output=True, text=True)
            (output / (case + "-mutant.log")).write_text(mutant.stdout + mutant.stderr)
        finally:
            fixture.write_text(original)
        # Require assertion failures in the named scenario, never a harness error.
        report = next(json.loads(line) for line in mutant.stdout.splitlines()
                      if line.startswith('{"tool":"pest"'))
        failures = report.get("failures", [])
        valid = (control.returncode == 0 and mutant.returncode == 1
                 and report.get("errors", 0) == 0 and failures
                 and all(case in failure["test"] for failure in failures))
        results.append({
            "case": case, "fixture": relative, "before": old, "after": new,
            "control_exit": control.returncode, "mutant_exit": mutant.returncode,
            "assertion_failures": len(failures), "green": bool(valid),
        })
    changed = any(
        digest != hashlib.sha256((repo / "tests/Fixtures/Crm" / relative).read_bytes()).hexdigest()
        for relative, digest in original_hashes.items()
    )
    manifest = {"scratch": str(scratch), "production_modified": changed,
                "fixture_hashes": original_hashes, "results": results}
    (output / "results.json").write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(manifest, ensure_ascii=False))
    return 0 if not changed and all(row["green"] for row in results) else 1


if __name__ == "__main__":
    raise SystemExit(run())
